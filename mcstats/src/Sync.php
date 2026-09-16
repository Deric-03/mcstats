<?php
/**
 * Synchronisation : lit les fichiers joueurs du serveur et met à jour la base.
 * Seuls les joueurs dont les fichiers ont changé sont relus.
 */
final class Sync
{
    const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Appelé par les pages web : lance la synchro si le cron ne l'a pas fait récemment. */
    public static function maybeRun(): void
    {
        if (!App::cfg('web_sync_fallback', true)) {
            return;
        }
        try {
            $last = (int) Db::meta('last_sync', 0);
            if (time() - $last < (int) App::cfg('sync_interval', 300) + 30) {
                return;
            }
            self::run(false, false);
        } catch (Throwable $e) {
            error_log('[mcstats] synchro web : ' . $e->getMessage());
        }
    }

    /**
     * @param bool $force recalcule tous les joueurs même si leurs fichiers n'ont pas changé
     * @param bool $cli   exécution en ligne de commande (plus de temps, plus de requêtes Mojang)
     */
    public static function run(bool $force = false, bool $cli = false, ?callable $log = null): array
    {
        $log = $log ?: function ($msg) {
        };
        // Lecture seule en secours : le verrou peut appartenir à un autre utilisateur (cron / Apache)
        $lockFile = App::dataDir() . '/sync.lock';
        $lock = @fopen($lockFile, 'c') ?: @fopen($lockFile, 'r');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $log('Une synchronisation est déjà en cours.');
            return ['status' => 'locked'];
        }
        if ($cli) {
            @set_time_limit(0);
        }
        try {
            $res = self::doRun($force, $cli, $log);
            Db::setMeta('sync_error', '');
            return $res;
        } catch (Throwable $e) {
            try {
                if (Db::pdo()->inTransaction()) {
                    Db::pdo()->rollBack();
                }
                Db::setMeta('sync_error', $e->getMessage());
                Db::setMeta('last_sync', time());
            } catch (Throwable $e2) {
                // base inaccessible : rien de plus à faire
            }
            $log('ERREUR : ' . $e->getMessage());
            return ['status' => 'error', 'error' => $e->getMessage()];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function doRun(bool $force, bool $cli, callable $log): array
    {
        $t0 = microtime(true);
        // Statut du serveur (joueurs connectés), récupéré avant d'ouvrir la transaction
        $status = App::cfg('server.enabled', true) ? ServerStatus::get() : null;

        $path = (string) App::cfg('players_path');
        $dirs = self::detectDirs($path);
        if (!$dirs) {
            // l'état "en ligne" ne dépend pas des fichiers : on le met à jour quand même
            self::updateOnline($status, time());
            throw new RuntimeException("Dossier des joueurs introuvable ou illisible : $path");
        }
        $files = self::listPlayers($dirs);
        $log(sprintf('%d joueur(s) trouvé(s) dans %s', count($files), $path));

        $pdo = Db::pdo();
        $existing = [];
        foreach (Db::all('SELECT uuid, name, name_checked, first_seen, last_seen, online, online_since, mtime_stats, mtime_adv, mtime_dat FROM players') as $r) {
            $existing[$r['uuid']] = $r;
        }

        $sig = self::calcSignature();
        if (Db::meta('calc_sig') !== $sig) {
            $force = true;
        }

        $now = time();
        $today = date('Y-m-d', $now);
        $histToday = [];
        foreach (Db::all('SELECT uuid FROM player_history WHERE day = ?', [$today]) as $r) {
            $histToday[$r['uuid']] = true;
        }

        $catCols = array_keys(Stats::CATEGORIES);
        $cols = array_merge(
            ['uuid', 'name', 'hidden', 'first_seen', 'last_seen', 'gamemode', 'online', 'online_since', 'stats_json', 'adv_json', 'nbt_json', 'mtime_stats', 'mtime_adv', 'mtime_dat', 'name_checked', 'updated_at', 'pos_dim', 'pos_x', 'pos_y', 'pos_z'],
            $catCols
        );
        $insert = $pdo->prepare('REPLACE INTO players (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $history = $pdo->prepare('REPLACE INTO player_history (uuid, day, play_time, score, mob_kills, deaths, blocks_mined, distance, advancements) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $usercache = null;
        $lookups = $cli ? 100 : 2;
        $updated = 0;
        $skipped = 0;
        $unreadable = 0;

        $pdo->beginTransaction();
        foreach ($files as $uuid => $f) {
            $mt = [
                'stats' => isset($f['stats']) ? (int) @filemtime($f['stats']) : 0,
                'adv'   => isset($f['adv']) ? (int) @filemtime($f['adv']) : 0,
                'dat'   => isset($f['dat']) ? (int) @filemtime($f['dat']) : 0,
            ];
            $old = $existing[$uuid] ?? null;
            $changed = $force || !$old
                || (int) $old['mtime_stats'] !== $mt['stats']
                || (int) $old['mtime_adv'] !== $mt['adv']
                || (int) $old['mtime_dat'] !== $mt['dat']
                || !isset($histToday[$uuid]);
            if (!$changed) {
                $skipped++;
                continue;
            }

            $stats = isset($f['stats']) ? self::readStats($f['stats']) : [];
            $adv = isset($f['adv']) ? self::readAdvancements($f['adv']) : ['list' => [], 'count' => 0, 'first' => 0];
            $player = null;
            if (isset($f['dat'])) {
                if (is_readable($f['dat'])) {
                    $player = self::readPlayer($f['dat']);
                } else {
                    // Le serveur écrit les .dat en rw------- : illisibles pour un autre utilisateur.
                    // On garde les dernières données connues plutôt que de les effacer.
                    $unreadable++;
                    $prevNbt = $old ? Db::value('SELECT nbt_json FROM players WHERE uuid = ?', [$uuid]) : null;
                    $player = $prevNbt ? json_decode((string) $prevNbt, true) : null;
                }
            }

            // Pseudo : .dat (Paper/Spigot) > usercache.json > base > API Mojang
            $name = (string) ($player['name'] ?? '');
            $nameChecked = (int) ($old['name_checked'] ?? 0);
            if ($name === '') {
                if ($usercache === null) {
                    $usercache = self::loadUsercache();
                }
                $name = $usercache[$uuid] ?? '';
            }
            if ($name === '' && $old && $old['name'] !== '') {
                $name = $old['name'];
            }
            if ($name === '' && App::cfg('mojang_lookup', true) && $lookups > 0 && $now - $nameChecked > 86400) {
                $lookups--;
                $nameChecked = $now;
                $name = self::mojangName($uuid) ?? '';
            }

            $values = Stats::compute($stats, $adv['count'], $player);

            $firstCandidates = array_filter([
                (int) ($player['first_played'] ?? 0),
                (int) $adv['first'],
                (int) ($old['first_seen'] ?? 0),
            ]);
            $first = $firstCandidates ? min($firstCandidates) : min(array_filter($mt) ?: [$now]);
            $last = max((int) ($player['last_played'] ?? 0), $mt['stats'], $mt['dat'], (int) ($old['last_seen'] ?? 0));

            if ($player) {
                unset($player['name'], $player['first_played'], $player['last_played']);
            }

            $row = [
                $uuid,
                mb_substr($name, 0, 32),
                0,
                $first,
                $last,
                (int) ($player['gamemode'] ?? 0),
                (int) ($old['online'] ?? 0),
                (int) ($old['online_since'] ?? 0),
                json_encode($stats, JSON_UNESCAPED_SLASHES),
                json_encode($adv['list'], JSON_UNESCAPED_SLASHES),
                $player ? json_encode($player, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                $mt['stats'],
                $mt['adv'],
                $mt['dat'],
                $nameChecked,
                $now,
                !empty($player['pos']) ? (string) ($player['dimension'] ?? 'minecraft:overworld') : '',
                (int) ($player['pos'][0] ?? 0),
                (int) ($player['pos'][1] ?? 0),
                (int) ($player['pos'][2] ?? 0),
            ];
            foreach ($catCols as $c) {
                $row[] = $values[$c] ?? 0;
            }
            $insert->execute($row);
            $history->execute([
                $uuid, $today, $values['play_time'], $values['score'], $values['mob_kills'], $values['deaths'],
                $values['blocks_mined'], $values['distance'], $values['advancements'],
            ]);
            $updated++;
        }

        // Joueurs dont les fichiers ont disparu (seulement si le dossier contient des joueurs)
        $removed = 0;
        if ($files) {
            foreach (array_diff(array_keys($existing), array_keys($files)) as $uuid) {
                Db::exec('DELETE FROM players WHERE uuid = ?', [$uuid]);
                Db::exec('DELETE FROM player_history WHERE uuid = ?', [$uuid]);
                $removed++;
            }
        }

        // Joueurs masqués
        Db::exec('UPDATE players SET hidden = 0 WHERE hidden <> 0');
        foreach ((array) App::cfg('hidden_players', []) as $h) {
            $h = trim((string) $h);
            if ($h !== '') {
                Db::exec('UPDATE players SET hidden = 1 WHERE uuid = ? OR LOWER(name) = LOWER(?)', [strtolower($h), $h]);
            }
        }

        // Fichiers .dat illisibles : signalés dans le pied de page du site
        $warning = '';
        if ($unreadable) {
            $warning = sprintf(
                "%d fichier(s) .dat illisible(s) par l'utilisateur « %s » : inventaires et positions non mis à jour. "
                . 'Lancez la synchronisation avec le propriétaire des fichiers du serveur Minecraft (voir le README).',
                $unreadable,
                self::currentUser()
            );
            $log('ATTENTION : ' . $warning);
        }
        Db::setMeta('sync_warning', $warning);

        // Joueurs connectés
        $online = self::updateOnline($status, $now);

        // Historique conservé 400 jours
        Db::exec('DELETE FROM player_history WHERE day < ?', [date('Y-m-d', $now - 400 * 86400)]);
        $pdo->commit();

        $info = [
            'players'  => count($files),
            'updated'  => $updated,
            'skipped'  => $skipped,
            'removed'  => $removed,
            'online'   => $online,
            'duration' => round((microtime(true) - $t0) * 1000),
            'mode'     => $cli ? 'cron' : 'web',
        ];
        Db::setMeta('last_sync', $now);
        Db::setMeta('calc_sig', $sig);
        Db::setMeta('last_sync_info', json_encode($info));
        $log(sprintf('Terminé en %d ms : %d mis à jour, %d inchangés, %d supprimés, %d en ligne.', $info['duration'], $updated, $skipped, $removed, $online));
        return ['status' => 'ok'] + $info;
    }

    /**
     * Met à jour l'état "en ligne" à partir du statut du serveur.
     * La liste envoyée par le serveur est limitée (12 joueurs en vanilla) : un joueur absent
     * de la liste n'est déclaré hors ligne que si la liste est complète.
     */
    private static function updateOnline(?array $status, int $now): int
    {
        if (!$status || empty($status['enabled']) || empty($status['online'])) {
            Db::exec('UPDATE players SET online = 0, online_since = 0, last_seen = ? WHERE online = 1', [$now]);
            return 0;
        }
        $ids = [];
        foreach ((array) ($status['players']['sample'] ?? []) as $p) {
            $id = strtolower((string) ($p['id'] ?? ''));
            if (preg_match(self::UUID_RE, $id)) {
                $ids[] = $id;
            }
        }
        foreach ($ids as $id) {
            Db::exec('UPDATE players SET online = 1, online_since = ? WHERE uuid = ? AND online = 0', [$now, $id]);
        }
        if (count($ids) >= (int) ($status['players']['online'] ?? 0)) {
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                Db::exec("UPDATE players SET online = 0, online_since = 0, last_seen = ? WHERE online = 1 AND uuid NOT IN ($in)", array_merge([$now], $ids));
            } else {
                Db::exec('UPDATE players SET online = 0, online_since = 0, last_seen = ? WHERE online = 1', [$now]);
            }
        }
        Db::exec('UPDATE players SET last_seen = ? WHERE online = 1', [$now]);
        return (int) Db::value('SELECT COUNT(*) FROM players WHERE online = 1 AND hidden = 0');
    }

    /** Détecte la structure des dossiers (récente "players/…" ou ancienne "playerdata/…"). */
    public static function detectDirs(string $path): ?array
    {
        $p = rtrim($path, '/\\');
        if ($p === '' || !is_dir($p)) {
            return null;
        }
        $dir = function ($d) {
            return is_dir($d) && is_readable($d) ? $d : null;
        };
        if (is_dir("$p/players/data") || is_dir("$p/players/stats")) {
            $p .= '/players';
        }
        if (is_dir("$p/playerdata")) {
            $r = ['data' => $dir("$p/playerdata"), 'stats' => $dir("$p/stats"), 'adv' => $dir("$p/advancements")];
        } else {
            $r = ['data' => $dir("$p/data"), 'stats' => $dir("$p/stats"), 'adv' => $dir("$p/advancements")];
        }
        return ($r['data'] || $r['stats']) ? $r : null;
    }

    /** uuid => ['stats' => fichier, 'adv' => fichier, 'dat' => fichier] */
    private static function listPlayers(array $dirs): array
    {
        $out = [];
        foreach (['stats' => '.json', 'adv' => '.json', 'data' => '.dat'] as $k => $ext) {
            if (!$dirs[$k]) {
                continue;
            }
            foreach (scandir($dirs[$k]) ?: [] as $file) {
                if (substr($file, -strlen($ext)) !== $ext) {
                    continue;
                }
                $uuid = strtolower(substr($file, 0, -strlen($ext)));
                if (preg_match(self::UUID_RE, $uuid)) {
                    $out[$uuid][$k === 'data' ? 'dat' : $k] = $dirs[$k] . '/' . $file;
                }
            }
        }
        ksort($out);
        return $out;
    }

    private static function readStats(string $file): array
    {
        $j = json_decode((string) @file_get_contents($file), true);
        return is_array($j['stats'] ?? null) ? $j['stats'] : [];
    }

    /** Succès (hors recettes) : id => [obtenu, date d'obtention, critères remplis]. */
    private static function readAdvancements(string $file): array
    {
        $j = json_decode((string) @file_get_contents($file), true);
        $out = ['list' => [], 'count' => 0, 'first' => 0];
        if (!is_array($j)) {
            return $out;
        }
        $known = Mc::advancements();
        $first = PHP_INT_MAX;
        foreach ($j as $id => $a) {
            if (!is_array($a)) {
                continue;
            }
            $dates = [];
            foreach ((array) ($a['criteria'] ?? []) as $when) {
                $ts = is_string($when) ? strtotime($when) : false;
                if ($ts) {
                    $dates[] = $ts;
                }
            }
            if ($dates) {
                $first = min($first, min($dates));
            }
            if (strpos($id, 'minecraft:recipes/') === 0) {
                continue;
            }
            $done = !empty($a['done']);
            $out['list'][$id] = [$done ? 1 : 0, $done && $dates ? max($dates) : 0, count($a['criteria'] ?? [])];
            if ($done && ($known ? isset($known[$id]) : strpos($id, 'minecraft:') === 0)) {
                $out['count']++;
            }
        }
        $out['first'] = $first === PHP_INT_MAX ? 0 : $first;
        return $out;
    }

    /** Lit le .dat et en extrait les informations utiles au site. */
    private static function readPlayer(string $file): ?array
    {
        $d = Nbt::readFile($file);
        if (!$d) {
            return null;
        }
        $bukkit = $d['bukkit'] ?? [];
        $paper = $d['Paper'] ?? [];

        $maxHealth = 20.0;
        foreach ((array) ($d['attributes'] ?? []) as $attr) {
            $aid = $attr['id'] ?? ($attr['Name'] ?? '');
            if (in_array($aid, ['minecraft:max_health', 'minecraft:generic.max_health', 'generic.maxHealth'], true)) {
                $maxHealth = (float) ($attr['base'] ?? ($attr['Base'] ?? 20));
            }
        }

        $dim = $d['Dimension'] ?? 'minecraft:overworld';
        if (is_int($dim)) {
            $dim = [0 => 'minecraft:overworld', -1 => 'minecraft:the_nether', 1 => 'minecraft:the_end'][$dim] ?? 'minecraft:overworld';
        }

        $p = [
            'name'         => (string) ($bukkit['lastKnownName'] ?? ''),
            'first_played' => isset($bukkit['firstPlayed']) ? intdiv((int) $bukkit['firstPlayed'], 1000) : 0,
            'last_played'  => intdiv(max((int) ($bukkit['lastPlayed'] ?? 0), (int) ($paper['LastSeen'] ?? 0)), 1000),
            'gamemode'     => (int) ($d['playerGameType'] ?? 0),
            'health'       => round((float) ($d['Health'] ?? 20), 1),
            'max_health'   => $maxHealth,
            'food'         => (int) ($d['foodLevel'] ?? 20),
            'saturation'   => round((float) ($d['foodSaturationLevel'] ?? 0), 1),
            'xp_level'     => (int) ($d['XpLevel'] ?? 0),
            'xp_progress'  => round((float) ($d['XpP'] ?? 0), 3),
            'xp_total'     => (int) ($d['XpTotal'] ?? 0),
            'dimension'    => (string) $dim,
            'pos'          => isset($d['Pos']) ? array_map(function ($v) {
                return (int) floor((float) $v);
            }, $d['Pos']) : null,
            'flying'       => !empty($d['abilities']['flying']),
            'selected'     => (int) ($d['SelectedItemSlot'] ?? 0),
            'effects'      => [],
            'inventory'    => [],
            'armor'        => [],
            'ender'        => [],
        ];

        if (isset($d['respawn']['pos'])) {
            $p['spawn'] = ['pos' => $d['respawn']['pos'], 'dim' => $d['respawn']['dimension'] ?? 'minecraft:overworld'];
        } elseif (isset($d['SpawnX'])) {
            $p['spawn'] = ['pos' => [(int) $d['SpawnX'], (int) $d['SpawnY'], (int) $d['SpawnZ']], 'dim' => $d['SpawnDimension'] ?? 'minecraft:overworld'];
        }
        if (isset($d['LastDeathLocation']['pos'])) {
            $p['last_death'] = ['pos' => $d['LastDeathLocation']['pos'], 'dim' => $d['LastDeathLocation']['dimension'] ?? 'minecraft:overworld'];
        }

        foreach ((array) ($d['active_effects'] ?? []) as $e) {
            $p['effects'][] = [
                'id'  => Mc::strip($e['id'] ?? ''),
                'amp' => (int) ($e['amplifier'] ?? 0),
                'dur' => (int) ($e['duration'] ?? 0),
            ];
        }

        foreach ((array) ($d['Inventory'] ?? []) as $it) {
            $item = self::simplifyItem($it);
            $slot = $item['slot'] ?? -1;
            if ($slot >= 100 && $slot <= 103) { // ancien format : armure
                $p['armor'][['feet', 'legs', 'chest', 'head'][$slot - 100]] = $item;
            } elseif ($slot === -106) {
                $p['armor']['offhand'] = $item;
            } else {
                $p['inventory'][] = $item;
            }
        }
        foreach ((array) ($d['equipment'] ?? []) as $slot => $it) {
            if (is_array($it)) {
                $p['armor'][$slot] = self::simplifyItem($it);
            }
        }
        foreach ((array) ($d['EnderItems'] ?? []) as $it) {
            $p['ender'][] = self::simplifyItem($it);
        }
        return $p;
    }

    /** Réduit un objet NBT à ce que le site affiche. */
    private static function simplifyItem(array $it, int $depth = 0): array
    {
        $o = [
            'id'    => Mc::strip($it['id'] ?? 'air'),
            'count' => (int) ($it['count'] ?? ($it['Count'] ?? 1)),
        ];
        if (isset($it['Slot'])) {
            $o['slot'] = (int) $it['Slot'];
        }
        $c = $it['components'] ?? [];
        if (isset($c['minecraft:custom_name'])) {
            $o['name'] = self::text($c['minecraft:custom_name']);
        }
        foreach (['minecraft:enchantments' => 'ench', 'minecraft:stored_enchantments' => 'stored'] as $k => $dst) {
            if (!empty($c[$k]) && is_array($c[$k])) {
                $levels = $c[$k]['levels'] ?? $c[$k];
                foreach ($levels as $eid => $lvl) {
                    if (is_int($lvl) || is_numeric($lvl)) {
                        $o[$dst][Mc::strip($eid)] = (int) $lvl;
                    }
                }
            }
        }
        if (isset($c['minecraft:damage']) && (int) $c['minecraft:damage'] > 0) {
            $o['damage'] = (int) $c['minecraft:damage'];
        }
        if (isset($c['minecraft:max_damage'])) {
            $o['max_damage'] = (int) $c['minecraft:max_damage'];
        }
        if (isset($c['minecraft:trim']['material'])) {
            $o['trim'] = [Mc::strip($c['minecraft:trim']['material']), Mc::strip($c['minecraft:trim']['pattern'] ?? '')];
        }
        if (isset($c['minecraft:potion_contents'])) {
            $pc = $c['minecraft:potion_contents'];
            $pid = is_string($pc) ? $pc : ($pc['potion'] ?? null);
            if ($pid) {
                $o['potion'] = Mc::strip($pid);
            }
        }
        if ($depth < 2) {
            foreach ((array) ($c['minecraft:container'] ?? []) as $entry) {
                if (isset($entry['item']) && is_array($entry['item'])) {
                    $sub = self::simplifyItem($entry['item'], $depth + 1);
                    $sub['slot'] = (int) ($entry['slot'] ?? 0);
                    $o['contents'][] = $sub;
                }
            }
            foreach ((array) ($c['minecraft:bundle_contents'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $o['contents'][] = self::simplifyItem($entry, $depth + 1);
                }
            }
        }
        return $o;
    }

    /** Convertit un composant texte (chaîne, JSON ou NBT) en texte brut. */
    public static function text($c): string
    {
        if (is_string($c)) {
            $t = ltrim($c);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[' || $t[0] === '"')) {
                $j = json_decode($t, true);
                if ($j !== null) {
                    return self::text($j);
                }
            }
            return preg_replace('/§./u', '', $c);
        }
        if (is_array($c)) {
            if ($c !== [] && array_keys($c) === range(0, count($c) - 1)) {
                return implode('', array_map([self::class, 'text'], $c));
            }
            $s = isset($c['text']) ? (string) $c['text'] : (isset($c['']) ? (string) $c[''] : '');
            if ($s === '' && isset($c['translate'])) {
                $s = (string) (Mc::t($c['translate']) ?? $c['fallback'] ?? $c['translate']);
            }
            foreach ((array) ($c['extra'] ?? []) as $e) {
                $s .= self::text($e);
            }
            return preg_replace('/§./u', '', $s);
        }
        return (string) $c;
    }

    private static function loadUsercache(): array
    {
        $out = [];
        $file = (string) App::cfg('usercache_path', '');
        if ($file !== '' && is_readable($file)) {
            foreach ((array) json_decode((string) file_get_contents($file), true) as $e) {
                if (isset($e['uuid'], $e['name'])) {
                    $out[strtolower($e['uuid'])] = $e['name'];
                }
            }
        }
        return $out;
    }

    private static function mojangName(string $uuid): ?string
    {
        $body = http_get('https://sessionserver.mojang.com/session/minecraft/profile/' . str_replace('-', '', $uuid), 4);
        $j = $body ? json_decode($body, true) : null;
        return isset($j['name']) && is_string($j['name']) ? $j['name'] : null;
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $u = posix_getpwuid(posix_geteuid());
            if (!empty($u['name'])) {
                return $u['name'];
            }
        }
        return get_current_user();
    }

    private static function calcSignature(): string
    {
        return md5(Stats::CALC_VERSION . '|' . json_encode(App::cfg('score_weights')) . '|' . Mc::version() . '|' . count(Mc::advancements()));
    }
}

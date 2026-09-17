<?php
/**
 * Données de plugins affichées sur les profils : sac à dos (Minepacks) et homes.
 *
 * Elles sont lues par la synchronisation, qui tourne sous le compte du serveur Minecraft,
 * et copiées dans la table plugin_data. Elles ne sont montrées qu'au joueur lui-même et aux admins.
 */
final class Plugins
{
    const SOURCES = [
        'backpack' => ['cfg' => 'plugins.minepacks_db', 'label' => 'Minepacks'],
        'homes'    => ['cfg' => 'plugins.homes_path', 'label' => 'Homes'],
    ];

    public static function source(string $plugin): string
    {
        return trim((string) App::cfg(self::SOURCES[$plugin]['cfg'], ''));
    }

    public static function enabled(string $plugin = ''): bool
    {
        if ($plugin !== '') {
            return self::source($plugin) !== '';
        }
        return self::enabled('backpack') || self::enabled('homes');
    }

    /** Sac à dos et homes : visibles par le joueur lui-même et par les admins (comptes activés). */
    public static function canSee(array $player): bool
    {
        return self::enabled() && Auth::enabled() && (Auth::isAdmin() || Auth::owns($player));
    }

    /** Données d'un joueur pour un plugin, ou null. */
    public static function data(string $plugin, string $uuid): ?array
    {
        $raw = Db::value('SELECT data FROM plugin_data WHERE plugin = ? AND uuid = ?', [$plugin, $uuid]);
        $d = $raw === null ? null : json_decode((string) $raw, true);
        return is_array($d) ? $d : null;
    }

    /** État de la dernière lecture de chaque plugin (pour l'espace admin). */
    public static function status(): array
    {
        return json_decode((string) Db::meta('plugins_info', ''), true) ?: [];
    }

    /** Appelé à chaque synchronisation : relit les sources qui ont changé. */
    public static function sync(bool $force, callable $log): void
    {
        $info = self::status();
        foreach (self::SOURCES as $plugin => $s) {
            $source = self::source($plugin);
            $sigKey = "plugin_sig_$plugin";
            if ($source === '') {
                if (isset($info[$plugin])) {
                    Db::exec('DELETE FROM plugin_data WHERE plugin = ?', [$plugin]);
                    Db::setMeta($sigKey, '');
                    unset($info[$plugin]);
                }
                continue;
            }
            try {
                $sig = md5($source . '|' . ($plugin === 'backpack' ? self::fileSignature($source) : self::dirSignature($source)));
                if (!$force && Db::meta($sigKey) === $sig && empty($info[$plugin]['error'])) {
                    continue;
                }
                $rows = $plugin === 'backpack' ? self::readBackpacks($source) : self::readHomes($source);
                $pdo = Db::pdo();
                $pdo->beginTransaction();
                Db::exec('DELETE FROM plugin_data WHERE plugin = ?', [$plugin]);
                $insert = $pdo->prepare('INSERT INTO plugin_data (plugin, uuid, data, updated_at) VALUES (?, ?, ?, ?)');
                foreach ($rows['players'] as $uuid => $data) {
                    $insert->execute([$plugin, $uuid, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), time()]);
                }
                $pdo->commit();
                Db::setMeta($sigKey, $sig);
                $info[$plugin] = ['count' => count($rows['players']), 'skipped' => $rows['skipped'], 'error' => '', 'at' => time()];
                $log(sprintf('%s : %d joueur(s) lu(s)%s.', $s['label'], count($rows['players']), $rows['skipped'] ? sprintf(', %d ignoré(s) (format non pris en charge)', $rows['skipped']) : ''));
            } catch (Throwable $e) {
                if (Db::pdo()->inTransaction()) {
                    Db::pdo()->rollBack();
                }
                // les dernières données lues restent affichées
                $info[$plugin] = ['count' => (int) ($info[$plugin]['count'] ?? 0), 'skipped' => 0, 'error' => $e->getMessage(), 'at' => time()];
                $log('ATTENTION : ' . $s['label'] . ' : ' . $e->getMessage());
            }
        }
        Db::setMeta('plugins_info', json_encode($info, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Minepacks, stockage SQLite (plugins/Minepacks/backpack.db). Les objets sont enregistrés au
     * format NBT (Minepacks 2.4 et plus) ; les sacs d'un format plus ancien sont ignorés.
     */
    private static function readBackpacks(string $file): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException("l'extension PHP SQLite est absente (paquet php-sqlite3).");
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("fichier introuvable ou illisible : $file");
        }
        $db = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $out = ['players' => [], 'skipped' => 0];
        $rows = $db->query('SELECT p.uuid, b.itemstacks, b.lastupdate FROM backpacks b JOIN backpack_players p ON p.player_id = b.owner');
        foreach ($rows as $r) {
            $uuid = self::uuid((string) $r['uuid']);
            $nbt = $uuid ? Nbt::readString((string) $r['itemstacks']) : null;
            if (!$uuid || !$nbt || !isset($nbt['Inventory'])) {
                $out['skipped'] += $uuid ? 1 : 0;
                continue;
            }
            $items = [];
            $maxSlot = -1;
            foreach ((array) $nbt['Inventory'] as $it) {
                if (is_array($it) && isset($it['Slot'])) {
                    $item = Sync::simplifyItem($it);
                    $items[] = $item;
                    $maxSlot = max($maxSlot, $item['slot']);
                }
            }
            $size = max((int) ($nbt['size'] ?? 0), $maxSlot + 1, 9);
            $out['players'][$uuid] = [
                'size'    => min(81, (int) ceil($size / 9) * 9),
                'items'   => $items,
                'updated' => (int) strtotime((string) $r['lastupdate']),
            ];
        }
        return $out;
    }

    /**
     * Homes : un fichier <uuid>.yml par joueur avec une section « homes » (plugin Homes, dossier
     * playerdata ; EssentialsX, dossier userdata). Chaque home : x, y, z et world (ou world-name).
     */
    private static function readHomes(string $dir): array
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            throw new RuntimeException("dossier introuvable ou illisible : $dir");
        }
        $out = ['players' => [], 'skipped' => 0];
        foreach (scandir($dir) ?: [] as $file) {
            if (!preg_match('/^(.+)\.ya?ml$/i', $file, $m) || !($uuid = self::uuid($m[1]))) {
                continue;
            }
            $y = Yaml::parseFile("$dir/$file");
            $homes = [];
            foreach ((array) ($y['homes'] ?? []) as $name => $h) {
                if (!is_array($h) || !isset($h['x'], $h['y'], $h['z']) || !is_numeric($h['x']) || !is_numeric($h['z'])) {
                    continue;
                }
                $world = (string) ($h['world-name'] ?? '');
                if ($world === '') {
                    $world = (string) ($h['world'] ?? '');
                }
                $homes[] = [
                    'name'  => (string) $name,
                    'world' => $world,
                    'pos'   => [(int) floor((float) $h['x']), (int) floor((float) $h['y']), (int) floor((float) $h['z'])],
                ];
            }
            if ($homes) {
                usort($homes, function ($a, $b) {
                    return strnatcasecmp($a['name'], $b['name']);
                });
                $out['players'][$uuid] = ['homes' => $homes];
            }
        }
        return $out;
    }

    /** UUID avec tirets en minuscules, ou null. */
    private static function uuid(string $s): ?string
    {
        $h = strtolower(str_replace('-', '', trim($s)));
        if (!preg_match('/^[0-9a-f]{32}$/', $h)) {
            return null;
        }
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    private static function fileSignature(string $file): string
    {
        $sig = '';
        foreach ([$file, "$file-wal"] as $f) {
            $sig .= is_file($f) ? filemtime($f) . ':' . filesize($f) . ';' : '-;';
        }
        return $sig;
    }

    private static function dirSignature(string $dir): string
    {
        $sig = '';
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $f) {
            if ($f[0] !== '.') {
                $sig .= $f . ':' . @filemtime("$dir/$f") . ':' . @filesize("$dir/$f") . ';';
            }
        }
        return md5($sig);
    }
}

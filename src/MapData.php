<?php
/**
 * Carte : lecture des données générées par Pl3xMap (mondes, joueurs en direct)
 * et fusion avec les dernières positions connues en base.
 *
 * Pl3xMap écrit :
 *  - tiles/settings.json            réglages globaux, liste des mondes, joueurs en ligne
 *  - tiles/<monde>/settings.json    zoom, point d'apparition
 *  - tiles/<monde>/<zoom>/<rendu>/<x>_<z>.<format>   images de 512 × 512 (zoom 0 = 1 pixel par bloc)
 */
final class MapData
{
    const LABELS = ['overworld' => 'Surface', 'nether' => 'Nether', 'end' => "L'End"];

    private static $settings = false;

    public static function enabled(): bool
    {
        return (bool) App::cfg('map.enabled', false);
    }

    public static function tilesPath(): string
    {
        return rtrim((string) App::cfg('map.tiles_path', APP_ROOT . '/map/tiles'), '/\\');
    }

    public static function tilesUrl(): string
    {
        return rtrim((string) App::cfg('map.tiles_url', ''), '/');
    }

    /** Positions des joueurs hors ligne affichées ? */
    public static function showOffline(): bool
    {
        return App::cfg('show_position', true) && App::cfg('map.show_offline_players', true);
    }

    private static function json(string $file): ?array
    {
        $raw = @file_get_contents($file);
        $j = $raw === false ? null : json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    /** tiles/settings.json de Pl3xMap (lu une fois par requête). */
    public static function settings(): ?array
    {
        if (self::$settings === false) {
            self::$settings = self::json(self::tilesPath() . '/settings.json');
        }
        return self::$settings;
    }

    /** Type de dimension d'après un nom de monde ou une dimension ("minecraft:the_nether"). */
    public static function typeOf(string $name, string $type = ''): string
    {
        $k = strtolower($name . ' ' . $type);
        if (strpos($k, 'nether') !== false) {
            return 'nether';
        }
        if (preg_match('/(the_end|_end\b|\bend\b)/', $k)) {
            return 'end';
        }
        return 'overworld';
    }

    /** Mondes rendus par Pl3xMap, triés, indexés par nom. */
    public static function worlds(): array
    {
        $s = self::settings();
        if (!$s) {
            return [];
        }
        $format = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($s['format'] ?? 'png'))) ?: 'png';
        $out = [];
        $seenTypes = [];
        foreach ((array) ($s['worldSettings'] ?? []) as $w) {
            $name = (string) ($w['name'] ?? '');
            if ($name === '' || strpbrk($name, '/\\') !== false) {
                continue;
            }
            $ws = self::json(self::tilesPath() . '/' . $name . '/settings.json') ?? [];
            $renderer = null;
            foreach ((array) ($w['renderers'] ?? ($ws['renderers'] ?? [])) as $r) {
                $renderer = is_array($r) ? ($r['label'] ?? $r['key'] ?? $r['name'] ?? null) : (string) $r;
                if ($renderer) {
                    break;
                }
            }
            if (!$renderer) {
                continue;
            }
            $type = self::typeOf($name, (string) ($w['type'] ?? ''));
            $label = self::LABELS[$type];
            if (isset($seenTypes[$type])) {
                $label .= ' (' . strip_tags((string) ($w['displayName'] ?? $name)) . ')';
            }
            $seenTypes[$type] = true;
            $zoom = (array) ($ws['zoom'] ?? []);
            $out[$name] = [
                'name'     => $name,
                'type'     => $type,
                'label'    => $label,
                'renderer' => (string) $renderer,
                'format'   => $format,
                'maxOut'   => max(0, (int) ($zoom['maxOut'] ?? 3)),
                'maxIn'    => max(0, (int) ($zoom['maxIn'] ?? 2)),
                'zoom'     => (int) ($zoom['default'] ?? 0),
                'spawn'    => ['x' => (int) ($ws['spawn']['x'] ?? 0), 'z' => (int) ($ws['spawn']['z'] ?? 0)],
                'order'    => (int) ($w['order'] ?? 0),
            ];
        }
        uasort($out, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        return $out;
    }

    /** Nom du monde Pl3xMap correspondant à une dimension. */
    public static function worldFor(string $dimension, array $worlds): ?string
    {
        $n = str_replace(':', '-', $dimension);
        if (isset($worlds[$n])) {
            return $n;
        }
        $type = self::typeOf($dimension);
        foreach ($worlds as $name => $w) {
            if ($w['type'] === $type) {
                return $name;
            }
        }
        return null;
    }

    /** Joueurs à placer sur la carte : positions en direct (Pl3xMap) sinon dernières positions connues. */
    public static function players(array $worlds): array
    {
        $live = [];
        foreach ((array) (self::settings()['players'] ?? []) as $p) {
            $id = strtolower((string) ($p['uuid'] ?? ''));
            if (!preg_match(Sync::UUID_RE, $id) || !isset($p['position']['x'], $p['position']['z'])) {
                continue;
            }
            $world = self::worldFor((string) ($p['world'] ?? ''), $worlds);
            if ($world) {
                $live[$id] = ['world' => $world, 'x' => (int) floor((float) $p['position']['x']), 'z' => (int) floor((float) $p['position']['z'])];
            }
        }

        $showOffline = self::showOffline();
        $out = [];
        foreach (Db::all('SELECT uuid, name, online, last_seen, pos_dim, pos_x, pos_z FROM players WHERE hidden = 0') as $r) {
            $uuid = $r['uuid'];
            $online = !empty($r['online']) || isset($live[$uuid]);
            $pos = $live[$uuid] ?? null;
            if (!$pos && ($online || $showOffline) && $r['pos_dim'] !== '') {
                $world = self::worldFor((string) $r['pos_dim'], $worlds);
                if ($world) {
                    $pos = ['world' => $world, 'x' => (int) $r['pos_x'], 'z' => (int) $r['pos_z']];
                }
            }
            if (!$pos) {
                continue;
            }
            $out[] = [
                'uuid'   => $uuid,
                'name'   => display_name($r),
                'online' => $online,
                'live'   => isset($live[$uuid]),
                'seen'   => $online ? 'En ligne' : 'Vu ' . fmt_ago($r['last_seen']),
                'url'    => player_url($r),
                'head'   => head_url($uuid, 32),
            ] + $pos;
        }
        usort($out, function ($a, $b) {
            return [$b['online'], strtolower($a['name'])] <=> [$a['online'], strtolower($b['name'])];
        });
        return $out;
    }
}

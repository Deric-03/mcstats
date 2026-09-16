<?php
/**
 * Requêtes de lecture utilisées par les pages.
 */
final class Repo
{
    private static function col(string $cat): string
    {
        if (!isset(Stats::CATEGORIES[$cat])) {
            throw new InvalidArgumentException("Catégorie inconnue : $cat");
        }
        return $cat;
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM players WHERE hidden = 0');
    }

    /** Classement d'une catégorie ; chaque ligne reçoit un rang (ex æquo partagés). */
    public static function leaderboard(string $cat, int $limit, int $offset = 0): array
    {
        $c = self::col($cat);
        $rows = Db::all(
            "SELECT uuid, name, last_seen, online, play_time, score, $c AS value FROM players WHERE hidden = 0
             ORDER BY $c DESC, play_time DESC, name ASC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $prev = null;
        $rank = 0;
        foreach ($rows as $i => &$r) {
            if ($i === 0) {
                $rank = 1 + (int) Db::value("SELECT COUNT(*) FROM players WHERE hidden = 0 AND $c > ?", [$r['value']]);
            } elseif ((float) $r['value'] !== (float) $prev) {
                $rank = $offset + $i + 1;
            }
            $r['rank'] = $rank;
            $prev = $r['value'];
        }
        unset($r);
        return $rows;
    }

    /** Rang du joueur dans chaque catégorie. */
    public static function ranks(array $player): array
    {
        $parts = [];
        $params = [];
        foreach (array_keys(Stats::CATEGORIES) as $k) {
            $parts[] = "SUM(CASE WHEN $k > ? THEN 1 ELSE 0 END) AS $k";
            $params[] = $player[$k];
        }
        $row = Db::one('SELECT ' . implode(', ', $parts) . ' FROM players WHERE hidden = 0', $params) ?: [];
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = (int) $v + 1;
        }
        return $out;
    }

    public static function findPlayer(string $q): ?array
    {
        $q = trim($q);
        $u = strtolower($q);
        if (preg_match('/^[0-9a-f]{32}$/', $u)) {
            $u = substr($u, 0, 8) . '-' . substr($u, 8, 4) . '-' . substr($u, 12, 4) . '-' . substr($u, 16, 4) . '-' . substr($u, 20);
        }
        if (preg_match(Sync::UUID_RE, $u)) {
            return Db::one('SELECT * FROM players WHERE uuid = ? AND hidden = 0', [$u]);
        }
        return Db::one('SELECT * FROM players WHERE LOWER(name) = LOWER(?) AND hidden = 0 ORDER BY last_seen DESC LIMIT 1', [$q]);
    }

    public static function search(string $q, int $limit = 8): array
    {
        $esc = strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']);
        return Db::all(
            "SELECT uuid, name, play_time, score, last_seen, online FROM players
             WHERE hidden = 0 AND (name LIKE ? ESCAPE '!' OR uuid LIKE ? ESCAPE '!')
             ORDER BY CASE WHEN LOWER(name) = LOWER(?) THEN 0 WHEN name LIKE ? ESCAPE '!' THEN 1 ELSE 2 END, last_seen DESC
             LIMIT " . (int) $limit,
            ['%' . $esc . '%', strtolower($esc) . '%', $q, $esc . '%']
        );
    }

    public static function totals(): array
    {
        return Db::one(
            'SELECT COUNT(*) AS players, SUM(play_time) AS play_time, SUM(mob_kills) AS mob_kills, SUM(player_kills) AS player_kills,
                    SUM(deaths) AS deaths, SUM(blocks_mined) AS blocks_mined, SUM(distance) AS distance,
                    SUM(advancements) AS advancements, SUM(diamonds) AS diamonds
             FROM players WHERE hidden = 0'
        ) ?: [];
    }

    public static function recent(int $limit): array
    {
        return Db::all('SELECT uuid, name, last_seen, online, play_time FROM players WHERE hidden = 0 ORDER BY online DESC, last_seen DESC LIMIT ' . (int) $limit);
    }

    public static function all(string $order = 'last_seen'): array
    {
        $orders = [
            'last_seen' => 'online DESC, last_seen DESC',
            'name'      => 'LOWER(name) ASC',
            'play_time' => 'play_time DESC',
            'score'     => 'score DESC',
            'first_seen' => 'first_seen ASC',
        ];
        $o = $orders[$order] ?? $orders['last_seen'];
        return Db::all("SELECT uuid, name, first_seen, last_seen, online, play_time, score, advancements FROM players WHERE hidden = 0 ORDER BY $o");
    }

    /** Historique quotidien (avec quelques jours de marge avant la fenêtre pour calculer les écarts). */
    public static function history(string $uuid, int $days): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days + 45) . ' days'));
        return Db::all('SELECT day, play_time, score, mob_kills, deaths, blocks_mined, distance, advancements FROM player_history WHERE uuid = ? AND day >= ? ORDER BY day ASC', [$uuid, $from]);
    }

    /** Sous-ensemble des UUID connus et visibles. */
    public static function knownUuids(array $uuids): array
    {
        $uuids = array_values(array_filter($uuids, function ($u) {
            return preg_match(Sync::UUID_RE, $u);
        }));
        if (!$uuids) {
            return [];
        }
        $rows = Db::all('SELECT uuid, name FROM players WHERE hidden = 0 AND uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')', $uuids);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['uuid']] = $r;
        }
        return $out;
    }
}

<?php
/**
 * Catalogue des catégories classées et calcul des valeurs dérivées.
 */
final class Stats
{
    /** À incrémenter quand le calcul change : force un recalcul complet à la prochaine synchro. */
    const CALC_VERSION = 2;

    /** Catégories classées. La clé est aussi le nom de la colonne SQL. */
    const CATEGORIES = [
        'score'          => ['label' => 'Score', 'icon' => 'nether_star', 'fmt' => 'score', 'type' => 'float', 'group' => 'Général', 'desc' => 'Note globale calculée à partir de toutes les statistiques'],
        'play_time'      => ['label' => 'Temps de jeu', 'icon' => 'clock', 'fmt' => 'ticks', 'group' => 'Général'],
        'advancements'   => ['label' => 'Succès', 'icon' => 'knowledge_book', 'group' => 'Général'],
        'xp_level'       => ['label' => "Niveau d'XP", 'icon' => 'experience_bottle', 'group' => 'Général', 'desc' => 'Niveau actuel (remis à zéro à la mort)'],
        'mob_kills'      => ['label' => 'Mobs tués', 'icon' => 'iron_sword', 'group' => 'Combat'],
        'player_kills'   => ['label' => 'Joueurs tués', 'icon' => 'netherite_sword', 'group' => 'Combat'],
        'deaths'         => ['label' => 'Morts', 'icon' => 'bone', 'group' => 'Combat'],
        'kd'             => ['label' => 'Ratio K/D', 'icon' => 'diamond_sword', 'fmt' => 'ratio', 'type' => 'float', 'group' => 'Combat', 'desc' => '(mobs + joueurs tués) ÷ morts'],
        'damage_dealt'   => ['label' => 'Dégâts infligés', 'icon' => 'netherite_axe', 'fmt' => 'hearts', 'group' => 'Combat'],
        'raids_won'      => ['label' => 'Raids gagnés', 'icon' => 'crossbow', 'group' => 'Combat'],
        'blocks_mined'   => ['label' => 'Blocs minés', 'icon' => 'diamond_pickaxe', 'group' => 'Minage'],
        'diamonds'       => ['label' => 'Diamants minés', 'icon' => 'diamond', 'group' => 'Minage', 'desc' => 'Minerais de diamant (normaux + deepslate)'],
        'ancient_debris' => ['label' => 'Débris antiques', 'icon' => 'ancient_debris', 'group' => 'Minage'],
        'items_crafted'  => ['label' => 'Objets fabriqués', 'icon' => 'crafting_table', 'group' => 'Minage'],
        'distance'       => ['label' => 'Distance parcourue', 'icon' => 'diamond_boots', 'fmt' => 'cm', 'group' => 'Exploration', 'desc' => 'Tous moyens de déplacement confondus (hors chutes)'],
        'jumps'          => ['label' => 'Sauts', 'icon' => 'rabbit_foot', 'group' => 'Exploration'],
        'animals_bred'   => ['label' => 'Animaux élevés', 'icon' => 'wheat', 'group' => 'Survie'],
        'fish_caught'    => ['label' => 'Poissons pêchés', 'icon' => 'fishing_rod', 'group' => 'Survie'],
        'trades'         => ['label' => 'Échanges villageois', 'icon' => 'emerald', 'group' => 'Survie'],
        'enchants'       => ['label' => 'Objets enchantés', 'icon' => 'enchanted_book', 'group' => 'Survie'],
    ];

    /** Libellés des pondérations du score (clé de config => libellé). */
    const SCORE_LABELS = [
        'play_time_hour' => 'heure de jeu',
        'advancement'    => 'succès',
        'mob_kill'       => 'mob tué',
        'player_kill'    => 'joueur tué',
        'death'          => 'mort',
        'block_mined'    => 'bloc miné',
        'diamond'        => 'diamant miné',
        'ancient_debris' => 'débris antique',
        'distance_km'    => 'km parcouru',
        'animal_bred'    => 'animal élevé',
        'trade'          => 'échange villageois',
        'fish'           => 'poisson pêché',
        'raid_won'       => 'raid gagné',
    ];

    const TIME_STATS = ['play_time', 'play_one_minute', 'total_world_time', 'time_since_death', 'time_since_rest', 'sneak_time'];

    public static function cat(string $key): ?array
    {
        return self::CATEGORIES[$key] ?? null;
    }

    /** Calcule toutes les valeurs classées d'un joueur. */
    public static function compute(array $stats, int $advancements, ?array $player): array
    {
        $custom = $stats['minecraft:custom'] ?? [];
        $g = function (string $k) use ($custom) {
            return (int) ($custom['minecraft:' . $k] ?? 0);
        };
        $mined = $stats['minecraft:mined'] ?? [];

        $v = [];
        $v['play_time'] = $g('play_time') ?: $g('play_one_minute');
        $v['advancements'] = $advancements;
        $v['xp_level'] = (int) ($player['xp_level'] ?? 0);
        $v['mob_kills'] = $g('mob_kills');
        $v['player_kills'] = $g('player_kills');
        $v['deaths'] = $g('deaths');
        $v['kd'] = round(($v['mob_kills'] + $v['player_kills']) / max(1, $v['deaths']), 2);
        $v['damage_dealt'] = $g('damage_dealt');
        $v['raids_won'] = $g('raid_win');
        $v['blocks_mined'] = (int) array_sum($mined);
        $v['diamonds'] = (int) (($mined['minecraft:diamond_ore'] ?? 0) + ($mined['minecraft:deepslate_diamond_ore'] ?? 0));
        $v['ancient_debris'] = (int) ($mined['minecraft:ancient_debris'] ?? 0);
        $v['items_crafted'] = (int) array_sum($stats['minecraft:crafted'] ?? []);
        $v['distance'] = self::distance($custom);
        $v['jumps'] = $g('jump');
        $v['animals_bred'] = $g('animals_bred');
        $v['fish_caught'] = $g('fish_caught');
        $v['trades'] = $g('traded_with_villager');
        $v['enchants'] = $g('enchant_item');
        $v['score'] = self::score($v);
        return $v;
    }

    /** Somme des distances (cm), chutes exclues. */
    public static function distance(array $custom): int
    {
        $total = 0;
        foreach (self::movements($custom) as $cm) {
            $total += $cm;
        }
        return $total;
    }

    /** Distances par moyen de déplacement (clé courte => cm), triées décroissant. */
    public static function movements(array $custom): array
    {
        $out = [];
        foreach ($custom as $k => $val) {
            $k = Mc::strip($k);
            if (substr($k, -7) === '_one_cm' && $k !== 'fall_one_cm' && $val > 0) {
                $out[$k] = (int) $val;
            }
        }
        arsort($out);
        return $out;
    }

    public static function score(array $v): float
    {
        $w = App::cfg('score_weights', []);
        $s = 0.0;
        $s += $v['play_time'] / 72000 * ($w['play_time_hour'] ?? 0);
        $s += $v['advancements'] * ($w['advancement'] ?? 0);
        $s += $v['mob_kills'] * ($w['mob_kill'] ?? 0);
        $s += $v['player_kills'] * ($w['player_kill'] ?? 0);
        $s += $v['deaths'] * ($w['death'] ?? 0);
        $s += $v['blocks_mined'] * ($w['block_mined'] ?? 0);
        $s += $v['diamonds'] * ($w['diamond'] ?? 0);
        $s += $v['ancient_debris'] * ($w['ancient_debris'] ?? 0);
        $s += $v['distance'] / 100000 * ($w['distance_km'] ?? 0);
        $s += $v['animals_bred'] * ($w['animal_bred'] ?? 0);
        $s += $v['trades'] * ($w['trade'] ?? 0);
        $s += $v['fish_caught'] * ($w['fish'] ?? 0);
        $s += $v['raids_won'] * ($w['raid_won'] ?? 0);
        return max(0, round($s));
    }

    /** Type d'affichage d'une statistique "custom" (clé sans namespace). */
    public static function customFormat(string $key): string
    {
        if (in_array($key, self::TIME_STATS, true)) {
            return 'ticks';
        }
        if (substr($key, -7) === '_one_cm') {
            return 'cm';
        }
        if (strpos($key, 'damage_') === 0) {
            return 'hearts';
        }
        return 'int';
    }
}

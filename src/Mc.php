<?php
/**
 * Données Minecraft : traductions françaises, icônes, liste des succès.
 * Les fichiers sont générés par "php cron/sync.php --assets" (voir Assets.php).
 */
final class Mc
{
    private static $lang;
    private static $assets;

    const DIMENSIONS = [
        'overworld'  => 'Surface',
        'the_nether' => 'Nether',
        'the_end'    => "L'End",
    ];

    const ADV_CATEGORIES = [
        'story'     => 'Minecraft',
        'nether'    => 'Nether',
        'end'       => "L'End",
        'adventure' => 'Aventure',
        'husbandry' => 'Agriculture',
    ];

    /** Icônes de secours pour les entités sans œuf d'apparition. */
    const ENTITY_ICONS = [
        'player'           => 'diamond_sword',
        'ender_dragon'     => 'dragon_egg',
        'wither'           => 'nether_star',
        'giant'            => 'zombie_spawn_egg',
        'illusioner'       => 'bow',
        'area_effect_cloud' => 'lingering_potion',
        'lightning_bolt'   => 'lightning_rod',
        'falling_block'    => 'anvil',
        'tnt'              => 'tnt',
        'arrow'            => 'arrow',
        'trident'          => 'trident',
        'fireball'         => 'fire_charge',
        'small_fireball'   => 'fire_charge',
        'potion'           => 'splash_potion',
    ];

    public static function lang(): array
    {
        if (self::$lang === null) {
            $f = App::dataDir() . '/lang_fr.php';
            self::$lang = is_file($f) ? (include $f) : [];
            if (!is_array(self::$lang)) {
                self::$lang = [];
            }
        }
        return self::$lang;
    }

    public static function assets(): array
    {
        if (self::$assets === null) {
            $f = App::dataDir() . '/assets.php';
            $a = is_file($f) ? (include $f) : null;
            self::$assets = is_array($a) ? $a : ['version' => '', 'icons' => [], 'advancements' => []];
        }
        return self::$assets;
    }

    public static function reload(): void
    {
        self::$lang = null;
        self::$assets = null;
    }

    public static function installed(): bool
    {
        return !empty(self::assets()['icons']);
    }

    public static function version(): string
    {
        return (string) (self::assets()['version'] ?? '');
    }

    public static function t(string $key, ?string $fallback = null): ?string
    {
        $l = self::lang();
        return $l[$key] ?? $fallback;
    }

    public static function strip(?string $id): string
    {
        $id = (string) $id;
        return strpos($id, 'minecraft:') === 0 ? substr($id, 10) : $id;
    }

    public static function pretty(?string $id): string
    {
        $id = self::strip($id);
        $id = substr($id, strrpos($id, ':') === false ? 0 : strrpos($id, ':') + 1);
        $id = substr($id, strrpos($id, '/') === false ? 0 : strrpos($id, '/') + 1);
        return ucfirst(str_replace('_', ' ', $id));
    }

    public static function itemName(?string $id): string
    {
        $k = self::strip($id);
        return self::t("item.minecraft.$k") ?? self::t("block.minecraft.$k") ?? self::pretty($id);
    }

    public static function blockName(?string $id): string
    {
        $k = self::strip($id);
        return self::t("block.minecraft.$k") ?? self::t("item.minecraft.$k") ?? self::pretty($id);
    }

    public static function entityName(?string $id): string
    {
        $k = self::strip($id);
        if ($k === 'player') {
            return 'Joueur';
        }
        return self::t("entity.minecraft.$k") ?? self::pretty($id);
    }

    public static function statName(?string $id): string
    {
        $k = self::strip($id);
        return self::t("stat.minecraft.$k") ?? self::pretty($id);
    }

    public static function enchantName(?string $id): string
    {
        $k = self::strip($id);
        return self::t("enchantment.minecraft.$k") ?? self::pretty($id);
    }

    public static function effectName(?string $id): string
    {
        $k = self::strip($id);
        return self::t("effect.minecraft.$k") ?? self::pretty($id);
    }

    public static function dimensionName(?string $id): string
    {
        $k = self::strip($id);
        return self::DIMENSIONS[$k] ?? self::pretty($id);
    }

    /** Nom d'une potion / flèche à effet : "Potion de rapidité". */
    public static function potionName(string $item, ?string $potion): ?string
    {
        if (!$potion) {
            return null;
        }
        $p = self::strip($potion);
        $p = preg_replace('/^(long|strong)_/', '', $p);
        return self::t('item.minecraft.' . self::strip($item) . '.effect.' . $p);
    }

    /** URL de l'icône d'un objet / bloc, ou null si inconnue. */
    public static function iconUrl(?string $id): ?string
    {
        $id = self::strip($id);
        $icons = self::assets()['icons'] ?? [];
        if ($id !== '' && isset($icons[$id])) {
            return 'assets/mc/' . $id . '.png?v=' . rawurlencode(self::version());
        }
        return null;
    }

    public static function entityIconId(?string $id): string
    {
        $k = self::strip($id);
        if (isset(self::ENTITY_ICONS[$k])) {
            return self::ENTITY_ICONS[$k];
        }
        return $k . '_spawn_egg';
    }

    /** Liste des succès vanilla (id => méta), dans l'ordre de l'arbre. */
    public static function advancements(): array
    {
        return self::assets()['advancements'] ?? [];
    }

    public static function advTitle(string $id, ?array $meta = null): string
    {
        $meta = $meta ?? (self::advancements()[$id] ?? null);
        if ($meta && $meta['title'] !== '') {
            return self::t($meta['title'], $meta['title_text'] ?? null) ?? self::pretty($id);
        }
        return self::pretty($id);
    }

    public static function advDescription(string $id, ?array $meta = null): string
    {
        $meta = $meta ?? (self::advancements()[$id] ?? null);
        if ($meta && $meta['desc'] !== '') {
            return (string) self::t($meta['desc'], '');
        }
        return '';
    }
}

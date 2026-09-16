<?php
/**
 * Installation des ressources Minecraft depuis les serveurs officiels de Mojang :
 *  - traductions françaises (fr_fr.json)
 *  - liste des succès (titres, icônes, cadres) extraite du client
 *  - icônes des objets / blocs extraites du client (assets/mc/*.png)
 */
final class Assets
{
    const MANIFEST = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';
    const RESOURCES = 'https://resources.download.minecraft.net/';

    public static function install(?callable $log = null, ?string $localJar = null): bool
    {
        $log = $log ?: function ($m) {
        };
        @set_time_limit(0);

        $manifest = self::json(self::MANIFEST);
        $want = trim((string) App::cfg('mc_version', '')) ?: $manifest['latest']['release'];
        $entry = null;
        foreach ($manifest['versions'] as $v) {
            if ($v['id'] === $want) {
                $entry = $v;
                break;
            }
        }
        if (!$entry) {
            throw new RuntimeException("Version Minecraft inconnue : $want");
        }
        $log("Version Minecraft : $want");
        $version = self::json($entry['url']);

        // 1. Traductions
        $index = self::json($version['assetIndex']['url']);
        $hash = $index['objects']['minecraft/lang/fr_fr.json']['hash'] ?? null;
        if (!$hash) {
            throw new RuntimeException('Traduction française introuvable dans les ressources Mojang');
        }
        $lang = self::json(self::RESOURCES . substr($hash, 0, 2) . '/' . $hash);
        self::writePhp(App::dataDir() . '/lang_fr.php', $lang);
        $log('Traductions françaises : ' . count($lang) . ' textes');

        // 2. Client (succès + icônes)
        $assets = ['version' => $want, 'icons' => [], 'advancements' => []];
        if (!class_exists('ZipArchive')) {
            $log("Extension PHP \"zip\" absente : icônes et liste des succès non installées (sudo apt install php-zip).");
            self::writePhp(App::dataDir() . '/assets.php', $assets);
            Mc::reload();
            return false;
        }
        $jar = $localJar;
        $tmp = null;
        if (!$jar) {
            $jar = $tmp = App::dataDir() . '/client.jar.part';
            $size = round(($version['downloads']['client']['size'] ?? 0) / 1048576);
            $log("Téléchargement du client Minecraft ($size Mo)…");
            if (!http_download($version['downloads']['client']['url'], $tmp)) {
                throw new RuntimeException('Téléchargement du client impossible');
            }
            $sha = $version['downloads']['client']['sha1'] ?? '';
            if ($sha && sha1_file($tmp) !== $sha) {
                @unlink($tmp);
                throw new RuntimeException('Client téléchargé corrompu (sha1 invalide)');
            }
        }
        $zip = new ZipArchive();
        if ($zip->open($jar) !== true) {
            throw new RuntimeException("Impossible d'ouvrir $jar");
        }
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[$zip->getNameIndex($i)] = $i;
            }
            $assets['advancements'] = self::extractAdvancements($zip, $names);
            $log('Succès : ' . count($assets['advancements']));
            $assets['icons'] = self::extractIcons($zip, $names, APP_ROOT . '/assets/mc', $lang);
            $log('Icônes : ' . count($assets['icons']));
        } finally {
            $zip->close();
            if ($tmp) {
                @unlink($tmp);
            }
        }
        self::writePhp(App::dataDir() . '/assets.php', $assets);
        Mc::reload();
        return true;
    }

    private static function extractAdvancements(ZipArchive $zip, array $names): array
    {
        $raw = [];
        foreach ($names as $name => $i) {
            if (!preg_match('#^data/minecraft/advancements?/(.+)\.json$#', $name, $m) || strpos($m[1], 'recipes/') === 0) {
                continue;
            }
            $j = json_decode((string) $zip->getFromIndex($i), true);
            if (!is_array($j) || empty($j['display'])) {
                continue;
            }
            $disp = $j['display'];
            $title = $disp['title'] ?? '';
            $desc = $disp['description'] ?? '';
            $icon = $disp['icon']['id'] ?? ($disp['icon']['item'] ?? 'minecraft:stone');
            $raw['minecraft:' . $m[1]] = [
                'cat'        => explode('/', $m[1])[0],
                'title'      => is_array($title) ? (string) ($title['translate'] ?? '') : '',
                'title_text' => is_string($title) ? $title : (string) ($title['text'] ?? ''),
                'desc'       => is_array($desc) ? (string) ($desc['translate'] ?? '') : '',
                'icon'       => Mc::strip($icon),
                'frame'      => (string) ($disp['frame'] ?? 'task'),
                'hidden'     => !empty($disp['hidden']),
                'parent'     => $j['parent'] ?? null,
                'req'        => count($j['requirements'] ?? ($j['criteria'] ?? [])),
            ];
        }

        // Ordre de l'arbre : parcours en profondeur depuis chaque racine
        $children = [];
        foreach ($raw as $id => $a) {
            $parent = ($a['parent'] && isset($raw[$a['parent']])) ? $a['parent'] : '';
            $children[$parent][] = $id;
        }
        foreach ($children as &$list) {
            sort($list);
        }
        unset($list);
        $catOrder = array_flip(array_keys(Mc::ADV_CATEGORIES));
        $roots = $children[''] ?? [];
        usort($roots, function ($a, $b) use ($raw, $catOrder) {
            return ($catOrder[$raw[$a]['cat']] ?? 99) <=> ($catOrder[$raw[$b]['cat']] ?? 99) ?: strcmp($a, $b);
        });
        $ordered = [];
        $walk = function ($id) use (&$walk, &$ordered, $children, $raw) {
            $ordered[$id] = $raw[$id];
            foreach ($children[$id] ?? [] as $c) {
                $walk($c);
            }
        };
        foreach ($roots as $r) {
            $walk($r);
        }
        return $ordered;
    }

    /**
     * Extrait une icône par objet / bloc.
     * Les icônes déjà présentes (autre version de Minecraft) sont conservées : le dossier cumule toutes
     * les versions installées, et la liste renvoyée correspond à tous les fichiers présents.
     */
    private static function extractIcons(ZipArchive $zip, array $names, string $dest, array $lang = []): array
    {
        if (!is_dir($dest) && !@mkdir($dest, 0775, true)) {
            throw new RuntimeException("Impossible de créer $dest");
        }
        // id => [définition d'objet ou blockstate (index zip), ou référence de modèle directe]
        $ids = [];
        foreach ($names as $name => $i) {
            if (preg_match('#^assets/minecraft/items/([a-z0-9_]+)\.json$#', $name, $m)) {
                $ids[$m[1]] = ['def', $i];
            }
        }
        if (!$ids) {
            // Avant 1.21.4 : pas de définitions d'objets, les modèles sont dans models/item/.
            // Ce dossier contient aussi des modèles techniques (clock_00, bow_pulling_0…) : on ne garde
            // que les identifiants qui ont une traduction, c'est-à-dire de vrais objets ou blocs.
            foreach ($names as $name => $i) {
                if (preg_match('#^assets/minecraft/models/item/([a-z0-9_]+)\.json$#', $name, $m)
                    && (!$lang || isset($lang['item.minecraft.' . $m[1]]) || isset($lang['block.minecraft.' . $m[1]]))) {
                    $ids[$m[1]] = ['model', 'minecraft:item/' . $m[1]];
                }
            }
        }
        foreach ($names as $name => $i) {
            if (preg_match('#^assets/minecraft/blockstates/([a-z0-9_]+)\.json$#', $name, $m) && !isset($ids[$m[1]])) {
                $ids[$m[1]] = ['def', $i];
            }
        }
        foreach ($ids as $id => [$kind, $ref]) {
            $model = $kind === 'model' ? $ref : self::findModel(json_decode((string) $zip->getFromIndex($ref), true));
            $tex = $model ? self::modelTexture($zip, $names, $model) : null;
            // Têtes et crânes : rendu 3D spécial, la texture de repli (sable des âmes) serait trompeuse
            if ($tex === 'block/soul_sand' && preg_match('/_(head|skull)$/', $id)) {
                continue;
            }
            $path = $tex ? 'assets/minecraft/textures/' . $tex . '.png' : null;
            if ($path && isset($names[$path])) {
                file_put_contents("$dest/$id.png", $zip->getFromIndex($names[$path]));
            }
        }
        $icons = [];
        foreach (glob($dest . '/*.png') ?: [] as $f) {
            $icons[basename($f, '.png')] = 1;
        }
        ksort($icons, SORT_STRING);
        return $icons;
    }

    /** Premier modèle référencé dans une définition d'objet ou un blockstate. */
    private static function findModel($j): ?string
    {
        if (!is_array($j)) {
            return null;
        }
        if (isset($j['model']) && is_string($j['model'])) {
            return $j['model'];
        }
        if (isset($j['base']) && is_string($j['base'])) {
            return $j['base'];
        }
        foreach ($j as $v) {
            if (is_array($v) && ($r = self::findModel($v))) {
                return $r;
            }
        }
        return null;
    }

    /** Texture la plus représentative d'un modèle (en suivant ses parents). */
    private static function modelTexture(ZipArchive $zip, array $names, string $ref): ?string
    {
        $tex = [];
        $cur = $ref;
        for ($guard = 0; $cur && $guard < 12; $guard++) {
            $cur = Mc::strip($cur);
            $path = "assets/minecraft/models/$cur.json";
            if (!isset($names[$path])) {
                break;
            }
            $j = json_decode((string) $zip->getFromIndex($names[$path]), true) ?: [];
            foreach ((array) ($j['textures'] ?? []) as $k => $v) {
                if (!isset($tex[$k]) && is_string($v)) {
                    $tex[$k] = $v;
                }
            }
            $cur = $j['parent'] ?? null;
        }
        $resolve = function ($v) use ($tex) {
            for ($g = 0; is_string($v) && strpos($v, '#') === 0 && $g < 6; $g++) {
                $v = $tex[substr($v, 1)] ?? null;
            }
            return (is_string($v) && strpos($v, '#') !== 0) ? Mc::strip($v) : null;
        };
        $prefer = ['layer0', 'side', 'all', 'front', 'top', 'end', 'texture', 'cross', 'plant', 'crop', 'torch', 'pattern', 'wool', 'particle'];
        foreach ($prefer as $k) {
            if (isset($tex[$k]) && ($r = $resolve($tex[$k]))) {
                return $r;
            }
        }
        foreach ($tex as $v) {
            if ($r = $resolve($v)) {
                return $r;
            }
        }
        return null;
    }

    private static function json(string $url): array
    {
        $body = http_get($url, 60);
        $j = $body ? json_decode($body, true) : null;
        if (!is_array($j)) {
            throw new RuntimeException("Téléchargement impossible : $url");
        }
        return $j;
    }

    private static function writePhp(string $file, array $data): void
    {
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, "<?php\n// Fichier généré automatiquement\nreturn " . var_export($data, true) . ";\n") === false) {
            throw new RuntimeException("Écriture impossible : $file");
        }
        rename($tmp, $file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}

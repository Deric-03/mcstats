<?php
/**
 * Fonctions utilitaires : échappement, formatage, rendu d'icônes, HTTP.
 */

/** URL d'un fichier statique du site, avec sa date de modification pour que les navigateurs rechargent chaque nouvelle version. */
function asset_url(string $path): string
{
    $file = APP_ROOT . '/' . $path;
    return $path . '?v=' . (is_file($file) ? filemtime($file) : APP_VERSION);
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Espace fine insécable utilisée comme séparateur de milliers. */
const NNBSP = "\u{202F}";

function fmt_int($n): string
{
    return number_format(round((float) $n), 0, ',', NNBSP);
}

function fmt_dec($n, int $decimals = 1): string
{
    return number_format((float) $n, $decimals, ',', NNBSP);
}

/** 12 345 -> "12,3 k", 1 234 567 -> "1,2 M" */
function fmt_compact($n): string
{
    $n = (float) $n;
    $a = abs($n);
    if ($a >= 1e9) {
        return fmt_dec($n / 1e9, $a >= 1e11 ? 0 : 1) . NNBSP . 'Md';
    }
    if ($a >= 1e6) {
        return fmt_dec($n / 1e6, $a >= 1e8 ? 0 : 1) . NNBSP . 'M';
    }
    if ($a >= 1e4) {
        return fmt_dec($n / 1e3, $a >= 1e5 ? 0 : 1) . NNBSP . 'k';
    }
    return fmt_int($n);
}

/** Ticks -> "61 h 20 min" */
function fmt_ticks($ticks): string
{
    $s = (int) floor($ticks / 20);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    if ($h > 0) {
        return fmt_int($h) . NNBSP . 'h' . ($h < 1000 ? ' ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . NNBSP . 'min' : '');
    }
    if ($m > 0) {
        return $m . NNBSP . 'min';
    }
    return $s . NNBSP . 's';
}

/** Ticks -> "61,3 h" */
function fmt_hours($ticks): string
{
    return fmt_dec($ticks / 72000, 1) . NNBSP . 'h';
}

/** Centimètres -> "612,4 km" / "850 m" */
function fmt_cm($cm): string
{
    $m = $cm / 100;
    if ($m >= 1000) {
        return fmt_dec($m / 1000, $m >= 100000 ? 0 : 1) . NNBSP . 'km';
    }
    return fmt_int($m) . NNBSP . 'm';
}

/** Les statistiques de dégâts sont stockées en dixièmes de demi-cœur. */
function fmt_hearts($v): string
{
    return fmt_int($v / 20) . NNBSP . '♥';
}

function fmt_cat(string $cat, $v): string
{
    switch (Stats::CATEGORIES[$cat]['fmt'] ?? 'int') {
        case 'ticks':
            return fmt_ticks($v);
        case 'cm':
            return fmt_cm($v);
        case 'ratio':
            return fmt_dec($v, 2);
        case 'hearts':
            return fmt_hearts($v);
        default:
            return fmt_int($v);
    }
}

function fmt_custom(string $key, $v): string
{
    switch (Stats::customFormat(Mc::strip($key))) {
        case 'ticks':
            return fmt_ticks($v);
        case 'cm':
            return fmt_cm($v);
        case 'hearts':
            return fmt_hearts($v);
        default:
            return fmt_int($v);
    }
}

function fmt_date($ts): string
{
    return $ts ? date('d/m/Y', (int) $ts) : '—';
}

function fmt_datetime($ts): string
{
    return $ts ? date('d/m/Y à H:i', (int) $ts) : '—';
}

function fmt_ago($ts): string
{
    if (!$ts) {
        return 'jamais';
    }
    $d = time() - (int) $ts;
    if ($d < 60) {
        return "à l'instant";
    }
    if ($d < 3600) {
        return 'il y a ' . intdiv($d, 60) . NNBSP . 'min';
    }
    if ($d < 86400) {
        return 'il y a ' . intdiv($d, 3600) . NNBSP . 'h';
    }
    if ($d < 86400 * 30) {
        $j = intdiv($d, 86400);
        return 'il y a ' . $j . ' jour' . ($j > 1 ? 's' : '');
    }
    return 'le ' . fmt_date($ts);
}

/** Durée en secondes -> "25 min", "1 h 12" */
function fmt_duration(int $s): string
{
    if ($s < 60) {
        return "moins d'une minute";
    }
    $m = intdiv($s, 60);
    if ($m < 60) {
        return $m . NNBSP . 'min';
    }
    $h = intdiv($m, 60);
    return $h . NNBSP . 'h' . ($m % 60 ? ' ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) : '');
}

function roman(int $n): string
{
    if ($n <= 0 || $n > 10) {
        return (string) $n;
    }
    return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'][$n];
}

/**
 * Identifiant à donner au service de skins : l'identifiant de texture du skin actuel s'il est connu,
 * sinon l'UUID. Avec l'identifiant de texture, un changement de skin change l'adresse de l'image :
 * les caches (6 h chez mc-heads.net) ne peuvent plus afficher l'ancien skin.
 */
function skin_id(string $uuid): string
{
    static $skins = null;
    if ($skins === null) {
        $skins = [];
        try {
            foreach (Db::all("SELECT uuid, skin FROM players WHERE skin <> ''") as $r) {
                $skins[$r['uuid']] = $r['skin'];
            }
        } catch (Throwable $e) {
            // base indisponible : on garde l'UUID
        }
    }
    return $skins[strtolower($uuid)] ?? $uuid;
}

function head_url(string $uuid, int $size = 64): string
{
    return rtrim((string) App::cfg('skin_url'), '/') . '/avatar/' . rawurlencode(skin_id($uuid)) . '/' . $size;
}

function body_url(string $uuid, int $size = 300): string
{
    return rtrim((string) App::cfg('skin_url'), '/') . '/body/' . rawurlencode(skin_id($uuid)) . '/' . $size;
}

function player_url(array $p): string
{
    return 'player.php?p=' . rawurlencode($p['name'] !== '' ? $p['name'] : $p['uuid']);
}

function display_name(array $p): string
{
    return $p['name'] !== '' ? $p['name'] : substr($p['uuid'], 0, 8);
}

/** Icône d'un objet / bloc (sprite pixelisé ou pastille avec l'initiale). */
function mc_icon(?string $id, string $title = '', string $cls = ''): string
{
    $id = Mc::strip($id);
    $url = Mc::iconUrl($id);
    $t = $title !== '' ? ' title="' . h($title) . '"' : '';
    if ($url) {
        return '<span class="mc-icon ' . h($cls) . '" style="background-image:url(\'' . h($url) . '\')"' . $t . '></span>';
    }
    $letter = mb_strtoupper(mb_substr(Mc::pretty($id), 0, 1));
    return '<span class="mc-icon mc-icon--ph ' . h($cls) . '"' . $t . '>' . h($letter) . '</span>';
}

function entity_icon(?string $id, string $cls = ''): string
{
    return mc_icon(Mc::entityIconId($id), '', $cls);
}

function json_attr($data): string
{
    return h(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function rank_class(int $rank): string
{
    return [1 => 'gold', 2 => 'silver', 3 => 'bronze'][$rank] ?? '';
}

/** Durabilité maximale des objets courants (pour la barre d'usure). */
function max_durability(string $id): int
{
    static $tools = ['wooden' => 59, 'stone' => 131, 'copper' => 190, 'iron' => 250, 'golden' => 32, 'diamond' => 1561, 'netherite' => 2031];
    static $armorMat = ['leather' => 5, 'chainmail' => 15, 'copper' => 11, 'iron' => 15, 'golden' => 7, 'diamond' => 33, 'netherite' => 37, 'turtle' => 25];
    static $armorPiece = ['helmet' => 11, 'chestplate' => 16, 'leggings' => 15, 'boots' => 13];
    static $fixed = [
        'elytra' => 432, 'trident' => 250, 'bow' => 384, 'crossbow' => 465, 'shield' => 336, 'fishing_rod' => 64,
        'shears' => 238, 'flint_and_steel' => 64, 'mace' => 500, 'brush' => 64, 'carrot_on_a_stick' => 25,
        'warped_fungus_on_a_stick' => 100, 'wolf_armor' => 64, 'turtle_helmet' => 275,
    ];
    $id = Mc::strip($id);
    if (isset($fixed[$id])) {
        return $fixed[$id];
    }
    if (preg_match('/^([a-z]+)_(sword|pickaxe|axe|shovel|hoe|spear)$/', $id, $m) && isset($tools[$m[1]])) {
        return $tools[$m[1]];
    }
    if (preg_match('/^([a-z]+)_(helmet|chestplate|leggings|boots)$/', $id, $m) && isset($armorMat[$m[1]])) {
        return $armorMat[$m[1]] * $armorPiece[$m[2]];
    }
    return 0;
}

/** Requête HTTP GET simple ; renvoie le corps ou null. */
function http_get(string $url, int $timeout = 10): ?string
{
    $ua = 'MCStats/' . APP_VERSION;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $ua,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            return $body;
        }
        if ($code >= 400) {
            return null;
        }
        // échec réseau / certificats côté cURL : on retente avec les flux PHP
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => $ua, 'ignore_errors' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/** Télécharge un fichier volumineux sur disque. */
function http_download(string $url, string $dest, int $timeout = 600): bool
{
    $fp = @fopen($dest, 'wb');
    if (!$fp) {
        return false;
    }
    $ok = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'MCStats/' . APP_VERSION,
        ]);
        $ok = curl_exec($ch) !== false && (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
    }
    if (!$ok) {
        ftruncate($fp, 0);
        rewind($fp);
        $src = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => $timeout]]));
        if ($src) {
            $ok = stream_copy_to_stream($src, $fp) > 0;
            fclose($src);
        }
    }
    fclose($fp);
    if (!$ok) {
        @unlink($dest);
    }
    return $ok;
}

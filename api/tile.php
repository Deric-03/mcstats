<?php
/**
 * Images de la carte (écrites par Pl3xMap dans map/tiles), servies par le site pour pouvoir les
 * réserver aux joueurs connectés : GET api/tile.php?t=<monde>/<zoom>/<rendu>/<x>_<z>.png
 */
require dirname(__DIR__) . '/src/bootstrap.php';

header('X-Content-Type-Options: nosniff');

if (!MapData::enabled() || !Auth::canSeePrivate()) {
    http_response_code(403);
    exit;
}
$path = (string) ($_GET['t'] ?? '');
if (!preg_match('#^([A-Za-z0-9_][A-Za-z0-9_.:-]*)/(\d{1,2})/([A-Za-z0-9_]+)/(-?\d{1,6})_(-?\d{1,6})\.(png|jpg|jpeg|gif|bmp|webp)$#', $path, $m)
    || strpos($m[1], '..') !== false) {
    http_response_code(404);
    exit;
}
$file = MapData::tilesPath() . '/' . $path;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

// Les images changent quand Pl3xMap redessine une zone : le navigateur revalide à chaque affichage (réponse 304 si inchangée)
$mtime = (int) filemtime($file);
$size = (int) filesize($file);
$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
header('Cache-Control: private, no-cache');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[$m[6]]);
header('Content-Length: ' . $size);
readfile($file);

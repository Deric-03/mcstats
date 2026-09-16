<?php
/**
 * Positions des joueurs pour la carte (JSON) : GET api/map.php
 */
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!MapData::enabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'Carte désactivée']);
    exit;
}
try {
    $players = MapData::players(MapData::worlds());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Données indisponibles']);
    exit;
}
if (!App::cfg('show_position', true)) {
    foreach ($players as &$p) {
        $p['hideCoords'] = true;
    }
    unset($p);
}
echo json_encode(['players' => $players, 'time' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

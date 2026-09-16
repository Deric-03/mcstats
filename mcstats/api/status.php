<?php
/**
 * Statut du serveur Minecraft (JSON) : GET api/status.php
 */
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$s = ServerStatus::get();
if (!empty($s['online']) && !empty($s['players']['sample'])) {
    try {
        $known = Repo::knownUuids(array_column($s['players']['sample'], 'id'));
    } catch (Throwable $e) {
        $known = [];
    }
    foreach ($s['players']['sample'] as &$p) {
        $p['head'] = head_url($p['id'], 48);
        $p['url'] = isset($known[$p['id']]) ? player_url($known[$p['id']]) : null;
    }
    unset($p);
}
echo json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

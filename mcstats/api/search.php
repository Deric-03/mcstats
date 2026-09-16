<?php
/**
 * Autocomplétion de la recherche : GET api/search.php?q=...
 */
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 36) {
    echo '[]';
    exit;
}
try {
    $rows = Repo::search($q, 8);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Base de données inaccessible']);
    exit;
}
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'name'      => display_name($r),
        'uuid'      => $r['uuid'],
        'url'       => player_url($r),
        'head'      => head_url($r['uuid'], 48),
        'play_time' => fmt_ticks($r['play_time']),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

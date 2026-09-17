<?php
/**
 * Homes à afficher sur la carte (JSON) : GET api/homes.php
 *
 *   (sans paramètre)  les homes du joueur connecté
 *   ?p=<pseudo|uuid>  les homes d'un joueur          (admins uniquement)
 *   ?all=1            les homes de tout le serveur   (admins uniquement)
 */
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$fail = function (int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
};

if (!MapData::enabled() || !Auth::enabled() || !Plugins::enabled('homes')) {
    $fail(404, 'Homes indisponibles');
}
if (!Auth::user()) {
    $fail(403, 'Connexion requise');
}

$who = trim((string) ($_GET['p'] ?? ''));
$all = ($_GET['all'] ?? '') !== '';
if (($all || $who !== '') && !Auth::isAdmin()) {
    $fail(403, 'Réservé aux admins');
}

if ($all) {
    $homes = Plugins::allHomes();
    $label = 'Tous les homes';
} elseif ($who !== '') {
    $player = Repo::findPlayer($who);
    if (!$player) {
        $fail(404, 'Joueur introuvable');
    }
    $homes = Plugins::homesOf($player['uuid'], display_name($player));
    $label = 'Homes de ' . display_name($player);
} else {
    $player = Plugins::ownPlayer();
    $homes = $player ? Plugins::homesOf($player['uuid'], display_name($player)) : [];
    $label = 'Mes homes';
}

echo json_encode(['homes' => $homes, 'label' => $label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

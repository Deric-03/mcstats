<?php
/**
 * Synchronisation en ligne de commande (à lancer par cron toutes les 5 minutes).
 *
 *   php cron/sync.php            synchronise les joueurs modifiés
 *   php cron/sync.php --force    recalcule tous les joueurs
 *   php cron/sync.php --assets   (ré)installe traductions, icônes et succès puis synchronise
 *   php cron/sync.php --jar=/chemin/client.jar   installe les ressources depuis un client local
 *   php cron/sync.php --quiet    n'affiche que les erreurs (pratique pour cron)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script se lance en ligne de commande.');
}
require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
$has = function (string ...$flags) use ($args) {
    return (bool) array_intersect($flags, $args);
};
if ($has('--help', '-h')) {
    echo "Usage : php cron/sync.php [--force] [--assets] [--jar=client.jar] [--quiet]\n";
    exit(0);
}
$quiet = $has('--quiet', '-q');
$log = function (string $msg) use ($quiet) {
    if (!$quiet || strpos($msg, 'ERREUR') === 0) {
        echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    }
};

$jar = null;
foreach ($args as $a) {
    if (strpos($a, '--jar=') === 0) {
        $jar = substr($a, 6);
    }
}

try {
    $doAssets = $has('--assets') || $jar !== null;
    // Installation automatique des ressources si elles manquent (une tentative par jour)
    if (!$doAssets && !Mc::installed() && time() - (int) Db::meta('assets_attempt', 0) > 86400) {
        $log('Ressources Minecraft absentes : installation automatique…');
        $doAssets = true;
    }
    if ($doAssets) {
        Db::setMeta('assets_attempt', time());
        try {
            Assets::install($log, $jar);
        } catch (Throwable $e) {
            $log('ERREUR ressources : ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    $log('ERREUR base de données : ' . $e->getMessage());
    exit(1);
}

$res = Sync::run($has('--force'), true, $log);
exit(($res['status'] ?? '') === 'error' ? 1 : 0);

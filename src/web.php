<?php
/**
 * Initialisation des pages HTML.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/components.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    Db::pdo();
} catch (Throwable $e) {
    error_log('[mcstats] base de données : ' . $e->getMessage());
    http_response_code(503);
    render_fatal('Base de données inaccessible', "Le site n'arrive pas à se connecter à la base de données. Vérifiez la section \"db\" de config.php.");
}

Sync::maybeRun();

function render_fatal(string $title, string $message): void
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . '</title><link rel="stylesheet" href="' . h(asset_url('assets/css/style.css')) . '"></head>'
        . '<body><main class="container main"><div class="card empty"><h1>' . h($title) . '</h1><p class="muted">' . h($message) . '</p></div></main></body></html>';
    exit;
}

<?php
require __DIR__ . '/src/web.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::enabled() && Auth::checkCsrf()) {
    Auth::logout();
}
header('Location: index.php');

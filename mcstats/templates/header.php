<?php
/**
 * En-tête commun.
 * Variables : $pageTitle (string), $nav (home|leaderboard|players|player)
 */
defined('APP_ROOT') || exit;

$siteName = (string) App::cfg('site_name', 'MC Stats');
$pageTitle = $pageTitle ?? '';
$nav = $nav ?? '';
$navLink = function (string $key, string $href, string $label) use ($nav) {
    return '<a href="' . $href . '"' . ($nav === $key ? ' class="is-active" aria-current="page"' : '') . '>' . $label . '</a>';
};
$favicon = Mc::iconUrl('grass_block');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle !== '' ? $pageTitle . ' · ' . $siteName : $siteName) ?></title>
<meta name="description" content="Statistiques, classements et profils des joueurs du serveur Minecraft <?= h($siteName) ?>.">
<?php if ($favicon): ?><link rel="icon" type="image/png" href="<?= h($favicon) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Silkscreen&display=swap">
<?php if (!empty($useMap)): ?><link rel="stylesheet" href="assets/vendor/leaflet.css"><?php endif; ?>
<link rel="stylesheet" href="assets/css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<header class="topbar">
  <div class="container topbar__inner">
    <a class="brand" href="index.php"><?= mc_icon('grass_block', '', 'brand__icon') ?><span class="brand__name"><?= h($siteName) ?></span></a>
    <nav class="nav" aria-label="Navigation principale">
      <?= $navLink('home', 'index.php', 'Accueil') ?>
      <?= $navLink('leaderboard', 'leaderboard.php', 'Classements') ?>
      <?= $navLink('players', 'players.php', 'Joueurs') ?>
      <?php if (MapData::enabled()): ?><?= $navLink('map', 'carte.php', 'Carte') ?><?php endif; ?>
    </nav>
    <form class="search search--top" action="player.php" method="get" role="search" data-search>
      <input type="search" name="p" placeholder="Rechercher un joueur…" autocomplete="off" aria-label="Rechercher un joueur" required>
      <div class="search__results" hidden></div>
    </form>
    <?php if (App::cfg('server.enabled', true)): ?>
      <a class="status-pill" href="index.php#serveur" data-status-pill><span class="dot"></span><span data-status-pill-text>Serveur…</span></a>
    <?php endif; ?>
  </div>
</header>
<main class="<?= h($mainClass ?? 'container main') ?>" id="contenu">

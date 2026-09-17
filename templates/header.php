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
<link rel="stylesheet" href="<?= h(asset_url('assets/css/style.css')) ?>">
</head>
<body<?= !empty($bodyClass) ? ' class="' . h($bodyClass) . '"' : '' ?>>
<header class="topbar<?= Auth::enabled() ? ' topbar--compact' : '' ?>">
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
    <?php if (Auth::enabled()): $me = Auth::user(); ?>
    <div class="account">
      <?php if ($me): ?>
        <?php if (!empty($me['is_admin'])): $pendingCount = Auth::pendingCount(); ?>
          <a class="account__admin<?= $nav === 'admin' ? ' is-active' : '' ?>" href="admin.php"<?= $pendingCount ? ' title="' . $pendingCount . ' demande(s) de whitelist en attente"' : '' ?>>Admin<?php if ($pendingCount): ?><span class="badge-count"><?= $pendingCount ?></span><?php endif; ?></a>
        <?php endif; ?>
        <a class="account__user<?= $nav === 'compte' ? ' is-active' : '' ?>" href="compte.php" title="Mon compte"><?= head_img($me['uuid'] !== '' ? $me['uuid'] : $me['username'], 24) ?><span><?= h($me['username']) ?></span></a>
      <?php else: $here = safe_return(basename((string) $_SERVER['SCRIPT_NAME']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); ?>
        <a class="btn btn--ghost btn--sm" href="connexion.php<?= $here !== '' && !in_array($nav, ['connexion', 'demande'], true) ? '?retour=' . h(rawurlencode($here)) : '' ?>">Connexion</a>
        <a class="btn btn--primary btn--sm" href="demande.php">Rejoindre</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</header>
<main class="<?= h($mainClass ?? 'container main') ?>" id="contenu">

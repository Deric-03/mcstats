<?php
require __DIR__ . '/src/web.php';

$sorts = [
    'last_seen'  => 'En ligne / dernière connexion',
    'play_time'  => 'Temps de jeu',
    'score'      => 'Score',
    'name'       => 'Pseudo',
    'first_seen' => 'Ancienneté',
];
$sort = (string) ($_GET['tri'] ?? 'last_seen');
if (!isset($sorts[$sort])) {
    $sort = 'last_seen';
}
$players = Repo::all($sort);

$pageTitle = 'Joueurs';
$nav = 'players';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
  <h1>Joueurs</h1>
  <p class="muted"><?= fmt_int(count($players)) ?> joueur<?= count($players) > 1 ? 's' : '' ?> sur le serveur</p>
</div>

<div class="toolbar">
  <input class="input" type="search" placeholder="Filtrer par pseudo…" data-filter-input="#player-grid" aria-label="Filtrer les joueurs">
  <form method="get" class="toolbar__sort">
    <label for="tri" class="muted">Trier par</label>
    <select class="input" id="tri" name="tri" data-autosubmit>
      <?php foreach ($sorts as $k => $label): ?>
        <option value="<?= h($k) ?>"<?= $k === $sort ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn--ghost" type="submit">OK</button></noscript>
  </form>
</div>

<?php if (!$players): ?>
  <div class="card empty"><p class="muted">Aucun joueur pour le moment.</p></div>
<?php else: ?>
<section class="player-grid" id="player-grid">
  <?php foreach ($players as $p): ?>
  <a class="card player-card" href="<?= h(player_url($p)) ?>" data-filter-text="<?= h(strtolower(display_name($p))) ?>">
    <?= head_img($p['uuid'], 56) ?>
    <span class="player-card__body">
      <strong class="player-card__name"><?= h(display_name($p)) ?><?= online_dot($p) ?></strong>
      <?= seen_html($p, 'Vu ') ?>
      <span class="player-card__stats">
        <span title="Temps de jeu"><?= mc_icon('clock') ?><?= h(fmt_hours($p['play_time'])) ?></span>
        <span title="Score"><?= mc_icon('nether_star') ?><?= h(fmt_int($p['score'])) ?></span>
        <span title="Succès"><?= mc_icon('knowledge_book') ?><?= (int) $p['advancements'] ?></span>
      </span>
    </span>
  </a>
  <?php endforeach; ?>
</section>
<p class="muted empty-note" data-filter-empty hidden>Aucun joueur ne correspond.</p>
<?php endif; ?>

<?php require APP_ROOT . '/templates/footer.php'; ?>

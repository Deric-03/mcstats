<?php
require __DIR__ . '/src/web.php';

$cat = (string) ($_GET['cat'] ?? 'score');
if (!isset(Stats::CATEGORIES[$cat])) {
    $cat = 'score';
}
$c = Stats::CATEGORIES[$cat];
$perPage = 50;
$total = Repo::count();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = Repo::leaderboard($cat, $perPage, ($page - 1) * $perPage);

$groups = [];
foreach (Stats::CATEGORIES as $k => $def) {
    $groups[$def['group']][$k] = $def;
}
$showPlayTime = $cat !== 'play_time';
$showScore = $cat !== 'score';
$podium = $page === 1 ? array_slice($rows, 0, 3) : [];

$pageTitle = 'Classement · ' . $c['label'];
$nav = 'leaderboard';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-head">
  <h1>Classements</h1>
  <p class="muted"><?= fmt_int($total) ?> joueur<?= $total > 1 ? 's' : '' ?> classé<?= $total > 1 ? 's' : '' ?></p>
</div>

<nav class="cat-nav" aria-label="Catégories">
  <?php foreach ($groups as $group => $cats): ?>
  <div class="cat-nav__group">
    <span class="cat-nav__label"><?= h($group) ?></span>
    <div class="chips">
      <?php foreach ($cats as $k => $def): ?>
        <a class="chip<?= $k === $cat ? ' is-active' : '' ?>" href="leaderboard.php?cat=<?= h($k) ?>"<?= $k === $cat ? ' aria-current="page"' : '' ?>><?= mc_icon($def['icon']) ?><?= h($def['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</nav>

<section class="card lb-head">
  <?= mc_icon($c['icon'], '', 'mc-icon--lg') ?>
  <div>
    <h2><?= h($c['label']) ?></h2>
    <?php if (!empty($c['desc'])): ?><p class="muted"><?= h($c['desc']) ?></p><?php endif; ?>
  </div>
  <?php if ($cat === 'score'): ?>
  <details class="score-help">
    <summary>Comment est calculé le score ?</summary>
    <ul>
      <?php foreach ((array) App::cfg('score_weights') as $k => $w): if (!$w || !isset(Stats::SCORE_LABELS[$k])) continue; ?>
        <li><strong><?= ($w > 0 ? '+' : '') . h(fmt_dec($w, $w == (int) $w ? 0 : 2)) ?></strong> par <?= h(Stats::SCORE_LABELS[$k]) ?></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</section>

<?php if (!$rows): ?>
  <div class="card empty"><p class="muted">Aucun joueur pour le moment. La première synchronisation n'a peut-être pas encore eu lieu.</p></div>
<?php else: ?>

<?php if (count($podium) >= 3): ?>
<section class="podium" aria-label="Podium">
  <?php foreach ([1, 0, 2] as $i): $r = $podium[$i]; ?>
  <a class="card podium__item podium__item--<?= $i + 1 ?>" href="<?= h(player_url($r)) ?>">
    <span class="podium__rank <?= rank_class($r['rank']) ?>"><?= $r['rank'] ?></span>
    <?= head_img($r['uuid'], $i === 0 ? 72 : 56, 'head podium__head') ?>
    <span class="podium__name"><?= h(display_name($r)) ?><?= online_dot($r) ?></span>
    <span class="podium__value"><?= h(fmt_cat($cat, $r['value'])) ?></span>
  </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="card table-wrap">
  <table class="table lb-table">
    <thead>
      <tr>
        <th class="col-rank">#</th>
        <th>Joueur</th>
        <th class="num"><?= h($c['label']) ?></th>
        <?php if ($showPlayTime): ?><th class="num hide-sm">Temps de jeu</th><?php endif; ?>
        <?php if ($showScore): ?><th class="num hide-sm">Score</th><?php endif; ?>
        <th class="hide-md">Dernière connexion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="col-rank"><?= rank_badge($r['rank']) ?></td>
        <td><a class="player-link" href="<?= h(player_url($r)) ?>"><?= head_img($r['uuid'], 28) ?><span><?= h(display_name($r)) ?></span><?= online_dot($r) ?></a></td>
        <td class="num strong"><?= h(fmt_cat($cat, $r['value'])) ?></td>
        <?php if ($showPlayTime): ?><td class="num hide-sm muted"><?= h(fmt_ticks($r['play_time'])) ?></td><?php endif; ?>
        <?php if ($showScore): ?><td class="num hide-sm muted"><?= h(fmt_int($r['score'])) ?></td><?php endif; ?>
        <td class="hide-md"><?= seen_html($r) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= pagination($page, $pages, function ($p) use ($cat) {
    return 'leaderboard.php?cat=' . rawurlencode($cat) . '&page=' . $p;
}) ?>
<?php endif; ?>

<?php require APP_ROOT . '/templates/footer.php'; ?>

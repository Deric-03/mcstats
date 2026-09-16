<?php
require __DIR__ . '/src/web.php';

$totals = Repo::totals();
$boards = ['score', 'play_time', 'mob_kills', 'advancements', 'blocks_mined', 'deaths'];
$tops = [];
foreach ($boards as $b) {
    $tops[$b] = Repo::leaderboard($b, 5);
}
$recent = Repo::recent(10);
$address = (string) App::cfg('server.display_address', '');
$minutes = max(1, (int) round(App::cfg('sync_interval', 60) / 60));
$refresh = $minutes === 1 ? 'chaque minute' : "toutes les $minutes minutes";

$pageTitle = '';
$nav = 'home';
require APP_ROOT . '/templates/header.php';
?>
<section class="hero">
  <div class="hero__text">
    <p class="eyebrow">Statistiques des joueurs</p>
    <h1 class="hero__title"><?= h(App::cfg('site_name')) ?></h1>
    <p class="hero__lead">Classements, profils détaillés, succès et inventaires de tous les joueurs du serveur. Mis à jour <?= h($refresh) ?>.</p>
    <form class="search search--hero" action="player.php" method="get" role="search" data-search>
      <input type="search" name="p" placeholder="Pseudo ou UUID d'un joueur…" autocomplete="off" aria-label="Rechercher un joueur" required>
      <button class="btn btn--primary" type="submit">Rechercher</button>
      <div class="search__results" hidden></div>
    </form>
  </div>

  <?php if (App::cfg('server.enabled', true)): ?>
  <aside class="card server" id="serveur" data-server>
    <div class="server__head">
      <img class="server__favicon" data-s-favicon alt="" width="48" height="48" hidden>
      <div class="server__id">
        <span class="server__label">Serveur Minecraft</span>
        <?php if ($address !== ''): ?>
          <button type="button" class="server__address" data-copy="<?= h($address) ?>" title="Copier l'adresse"><?= h($address) ?> <span class="server__copy">Copier</span></button>
        <?php endif; ?>
      </div>
      <span class="status-badge" data-s-badge><span class="dot"></span><span data-s-badge-text>Chargement…</span></span>
    </div>
    <div class="server__motd" data-s-motd></div>
    <dl class="server__stats">
      <div><dt>Joueurs</dt><dd data-s-players>–</dd></div>
      <div><dt>Version</dt><dd data-s-version>–</dd></div>
      <div><dt>Latence</dt><dd data-s-latency>–</dd></div>
    </dl>
    <div class="server__meter"><span data-s-meter style="width:0"></span></div>
    <div class="server__online" data-s-list></div>
  </aside>
  <?php endif; ?>
</section>

<section class="totals" aria-label="Statistiques du serveur">
  <?= mini_tile('Joueurs', fmt_int($totals['players'] ?? 0), 'name_tag') ?>
  <?= mini_tile('Heures de jeu', fmt_int(($totals['play_time'] ?? 0) / 72000) . NNBSP . 'h', 'clock', 'Temps de jeu cumulé de tous les joueurs') ?>
  <?= mini_tile('Mobs tués', fmt_compact($totals['mob_kills'] ?? 0), 'iron_sword', fmt_int($totals['mob_kills'] ?? 0)) ?>
  <?= mini_tile('Morts', fmt_compact($totals['deaths'] ?? 0), 'bone', fmt_int($totals['deaths'] ?? 0)) ?>
  <?= mini_tile('Blocs minés', fmt_compact($totals['blocks_mined'] ?? 0), 'diamond_pickaxe', fmt_int($totals['blocks_mined'] ?? 0)) ?>
  <?= mini_tile('Distance', fmt_cm($totals['distance'] ?? 0), 'diamond_boots', 'Distance totale parcourue') ?>
  <?= mini_tile('Diamants', fmt_compact($totals['diamonds'] ?? 0), 'diamond', fmt_int($totals['diamonds'] ?? 0) . ' minerais de diamant minés') ?>
</section>

<div class="section-head">
  <h2>Meilleurs joueurs</h2>
  <a class="link-more" href="leaderboard.php">Tous les classements →</a>
</div>
<section class="boards">
  <?php foreach ($tops as $cat => $rows): $c = Stats::CATEGORIES[$cat]; ?>
  <article class="card board">
    <header class="board__head">
      <?= mc_icon($c['icon']) ?>
      <h3><?= h($c['label']) ?></h3>
      <a class="link-more" href="leaderboard.php?cat=<?= h($cat) ?>">Voir tout</a>
    </header>
    <?php if (!$rows): ?>
      <p class="muted empty-note">Aucun joueur pour le moment.</p>
    <?php else: ?>
    <ol class="board__list">
      <?php foreach ($rows as $r): ?>
      <li>
        <a class="board__row" href="<?= h(player_url($r)) ?>">
          <?= rank_badge($r['rank']) ?>
          <?= head_img($r['uuid'], 28) ?>
          <span class="board__name"><?= h(display_name($r)) ?><?= online_dot($r) ?></span>
          <span class="board__value"><?= h(fmt_cat($cat, $r['value'])) ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</section>

<?php if ($recent): ?>
<div class="section-head">
  <h2>Dernières connexions</h2>
  <a class="link-more" href="players.php">Tous les joueurs →</a>
</div>
<section class="recent">
  <?php foreach ($recent as $r): ?>
  <a class="card recent__item" href="<?= h(player_url($r)) ?>">
    <?= head_img($r['uuid'], 40) ?>
    <span class="recent__text">
      <strong><?= h(display_name($r)) ?><?= online_dot($r) ?></strong>
      <?= seen_html($r) ?>
    </span>
  </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php require APP_ROOT . '/templates/footer.php'; ?>

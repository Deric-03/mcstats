<?php
require __DIR__ . '/src/web.php';

$enabled = MapData::enabled();
$worlds = $enabled ? MapData::worlds() : [];
$players = $worlds ? MapData::players($worlds) : [];

$pageTitle = 'Carte';
$nav = 'map';
$mainClass = 'main main--map';
$useMap = (bool) $worlds;
require APP_ROOT . '/templates/header.php';

if (!$worlds): ?>
<div class="container">
  <div class="card empty map-empty">
    <p><strong>La carte n'est pas encore disponible.</strong></p>
    <?php if (!$enabled): ?>
      <p class="muted">Elle s'active dans la section <code>map</code> de <code>config.php</code> une fois le plugin Pl3xMap installé (voir le README).</p>
    <?php else: ?>
      <p class="muted">Pl3xMap n'a pas encore généré la carte, ou son dossier est introuvable : <code><?= h(MapData::tilesPath()) ?></code></p>
    <?php endif; ?>
  </div>
</div>
<?php else:
    $config = [
        'tilesUrl'    => MapData::tilesUrl(),
        'api'         => 'api/map.php',
        'refresh'     => max(2, (int) App::cfg('map.refresh_seconds', 5)) * 1000,
        'worlds'      => array_values($worlds),
        'players'     => $players,
        'showCoords'  => (bool) App::cfg('show_position', true),
        'showOffline' => MapData::showOffline(),
        'spawnIcon'   => Mc::iconUrl('red_bed'),
        'focus'       => [
            'p' => (string) ($_GET['p'] ?? ''),
            'w' => (string) ($_GET['w'] ?? ''),
            'x' => isset($_GET['x']) ? (int) $_GET['x'] : null,
            'z' => isset($_GET['z']) ? (int) $_GET['z'] : null,
        ],
    ];
?>
<section class="map-layout">
  <aside class="map-panel" aria-label="Joueurs sur la carte">
    <div class="map-panel__head">
      <h1>Carte</h1>
      <span class="muted" data-map-count></span>
    </div>
    <div class="chips map-worlds" role="tablist" aria-label="Dimension">
      <?php foreach ($worlds as $w): ?>
        <button type="button" class="chip" role="tab" data-map-world="<?= h($w['name']) ?>"><?= mc_icon(['overworld' => 'grass_block', 'nether' => 'netherrack', 'end' => 'end_stone'][$w['type']]) ?><?= h($w['label']) ?></button>
      <?php endforeach; ?>
    </div>
    <input class="input" type="search" placeholder="Chercher un joueur…" autocomplete="off" aria-label="Chercher un joueur sur la carte" data-map-search>
    <?php if (MapData::showOffline()): ?>
      <label class="map-toggle"><input type="checkbox" data-map-offline checked> Afficher les joueurs hors ligne</label>
    <?php endif; ?>
    <ul class="map-players" data-map-list></ul>
  </aside>
  <div class="map-view">
    <div id="map" data-map-config="<?= json_attr($config) ?>"></div>
    <div class="map-coords" data-map-coords aria-live="off">X – · Z –</div>
  </div>
</section>
<?php endif; ?>

<?php require APP_ROOT . '/templates/footer.php'; ?>

<?php
require __DIR__ . '/src/web.php';

$q = trim((string) ($_GET['p'] ?? ''));
$player = $q !== '' ? Repo::findPlayer($q) : null;

// ---------------------------------------------------------------------------
// Joueur introuvable : résultats de recherche
// ---------------------------------------------------------------------------
if (!$player) {
    $matches = $q !== '' ? Repo::search($q, 30) : [];
    if (count($matches) === 1) {
        header('Location: ' . player_url($matches[0]));
        exit;
    }
    if (!$matches) {
        http_response_code(404);
    }
    $pageTitle = 'Recherche';
    $nav = 'player';
    require APP_ROOT . '/templates/header.php';
    ?>
    <div class="page-head"><h1>Recherche<?= $q !== '' ? ' : « ' . h($q) . ' »' : '' ?></h1></div>
    <?php if (!$matches): ?>
      <div class="card empty">
        <p><strong>Aucun joueur trouvé.</strong></p>
        <p class="muted">Vérifiez l'orthographe du pseudo ou parcourez la <a class="link-more" href="players.php">liste des joueurs</a>.</p>
      </div>
    <?php else: ?>
      <p class="muted"><?= count($matches) ?> joueurs correspondent à votre recherche.</p>
      <section class="player-grid">
        <?php foreach ($matches as $m): ?>
        <a class="card player-card" href="<?= h(player_url($m)) ?>">
          <?= head_img($m['uuid'], 48) ?>
          <span class="player-card__body"><strong class="player-card__name"><?= h(display_name($m)) ?></strong><span class="muted"><?= h(fmt_ticks($m['play_time'])) ?> de jeu</span></span>
        </a>
        <?php endforeach; ?>
      </section>
    <?php endif;
    require APP_ROOT . '/templates/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// Préparation des données du profil
// ---------------------------------------------------------------------------
$uuid = $player['uuid'];
$name = display_name($player);
$stats = json_decode((string) $player['stats_json'], true) ?: [];
$advState = json_decode((string) $player['adv_json'], true) ?: [];
$pd = $player['nbt_json'] ? json_decode((string) $player['nbt_json'], true) : null;

$strip = function ($a) {
    $o = [];
    foreach ((array) $a as $k => $v) {
        $o[Mc::strip($k)] = (int) $v;
    }
    return $o;
};
$custom = $strip($stats['minecraft:custom'] ?? []);
$killed = $strip($stats['minecraft:killed'] ?? []);
$killedBy = $strip($stats['minecraft:killed_by'] ?? []);
$mined = $strip($stats['minecraft:mined'] ?? []);
$crafted = $strip($stats['minecraft:crafted'] ?? []);
$used = $strip($stats['minecraft:used'] ?? []);
$pickedUp = $strip($stats['minecraft:picked_up'] ?? []);
$dropped = $strip($stats['minecraft:dropped'] ?? []);
$broken = $strip($stats['minecraft:broken'] ?? []);
$moves = Stats::movements($stats['minecraft:custom'] ?? []);

$ranks = Repo::ranks($player);
$total = Repo::count();
$showInv = App::cfg('show_inventory', true) && $pd;
$showPos = App::cfg('show_position', true) && $pd;

// Profil du joueur connecté, sac à dos et homes (visibles par le joueur lui-même et les admins)
$isMe = Auth::owns($player);
$pluginsTab = '';
if (Plugins::canSee($player)) {
    $hasBackpack = Plugins::enabled('backpack');
    $hasHomes = Plugins::enabled('homes');
    $pluginsTab = $hasBackpack && $hasHomes ? 'Sac à dos & homes' : ($hasBackpack ? 'Sac à dos' : 'Homes');
    $backpack = $hasBackpack ? Plugins::data('backpack', $uuid) : null;
    $homes = $hasHomes ? (Plugins::data('homes', $uuid)['homes'] ?? []) : [];
    $mapWorlds = $homes && MapData::enabled() ? MapData::worlds() : [];
    $homeWorld = function (string $world) use ($mapWorlds) {
        $type = MapData::typeOf($world);
        $label = isset($mapWorlds[$world]) ? $mapWorlds[$world]['label'] : MapData::LABELS[$type];
        if (!isset($mapWorlds[$world]) && !in_array($world, ['', 'world', 'world_nether', 'world_the_end'], true)) {
            $label .= " ($world)";
        }
        return mc_icon(['overworld' => 'grass_block', 'nether' => 'netherrack', 'end' => 'end_stone'][$type], $label) . '<span class="homes-table__label">' . h($label) . '</span>';
    };
}

const MOVE_ICONS = [
    'walk_one_cm' => 'iron_boots', 'sprint_one_cm' => 'sugar', 'crouch_one_cm' => 'chainmail_leggings',
    'swim_one_cm' => 'water_bucket', 'walk_on_water_one_cm' => 'lily_pad', 'walk_under_water_one_cm' => 'turtle_helmet',
    'fly_one_cm' => 'feather', 'aviate_one_cm' => 'elytra', 'climb_one_cm' => 'ladder', 'boat_one_cm' => 'oak_boat',
    'horse_one_cm' => 'saddle', 'minecart_one_cm' => 'minecart', 'pig_one_cm' => 'carrot_on_a_stick',
    'strider_one_cm' => 'warped_fungus_on_a_stick', 'happy_ghast_one_cm' => 'happy_ghast_spawn_egg', 'nautilus_one_cm' => 'nautilus_shell',
];
$moveIcon = function ($k) {
    return mc_icon(MOVE_ICONS[$k] ?? 'iron_boots');
};

// Faits marquants
$top = function (array $a) {
    $a = array_filter($a);
    if (!$a) {
        return null;
    }
    arsort($a);
    $k = array_key_first($a);
    return [$k, $a[$k]];
};
$highlights = [];
if ($t = $top($killed)) {
    $highlights[] = ['Mob le plus chassé', Mc::entityName($t[0]), fmt_int($t[1]) . ' tué' . ($t[1] > 1 ? 's' : ''), Mc::entityIconId($t[0])];
}
if ($t = $top($killedBy)) {
    $highlights[] = ['Némésis', Mc::entityName($t[0]), fmt_int($t[1]) . ' mort' . ($t[1] > 1 ? 's' : ''), Mc::entityIconId($t[0])];
}
if ($t = $top($mined)) {
    $highlights[] = ['Bloc le plus miné', Mc::blockName($t[0]), fmt_int($t[1]), $t[0]];
}
if ($t = $top($crafted)) {
    $highlights[] = ['Objet le plus fabriqué', Mc::itemName($t[0]), fmt_int($t[1]), $t[0]];
}
if ($t = $top($used)) {
    $highlights[] = ['Objet le plus utilisé', Mc::itemName($t[0]), fmt_int($t[1]) . ' fois', $t[0]];
}
if ($moves) {
    $k = array_key_first($moves);
    $highlights[] = ['Déplacement favori', Mc::statName($k), fmt_cm($moves[$k]), MOVE_ICONS[$k] ?? 'iron_boots'];
}

// Historique (30 derniers jours)
$history = Repo::history($uuid, 30);
$byDay = [];
foreach ($history as $r) {
    $byDay[$r['day']] = $r;
}
$start = new DateTime('today');
$start->modify('-29 days');
$startKey = $start->format('Y-m-d');
$prev = null;
foreach ($history as $r) {
    if ($r['day'] < $startKey) {
        $prev = (int) $r['play_time'];
    }
}
$dayLabels = $dayHours = $scoreLabels = $scoreValues = [];
$today = date('Y-m-d');
for ($d = clone $start; ($key = $d->format('Y-m-d')) <= $today; $d->modify('+1 day')) {
    $dayLabels[] = $d->format('d/m');
    if (isset($byDay[$key])) {
        $v = (int) $byDay[$key]['play_time'];
        $dayHours[] = $prev === null ? null : round(max(0, $v - $prev) / 72000, 2);
        $prev = $v;
        $scoreLabels[] = $d->format('d/m');
        $scoreValues[] = (float) $byDay[$key]['score'];
    } else {
        $dayHours[] = $prev === null ? null : 0;
    }
}
$hasDaily = count(array_filter($dayHours, function ($x) {
    return $x !== null;
})) > 0;
$hasScore = count($scoreValues) >= 2;

// Succès
$advList = Mc::advancements();
$advTotal = count($advList);
$advDone = (int) $player['advancements'];
$advOthers = [];
foreach ($advState as $id => $s) {
    if (!isset($advList[$id]) && !empty($s[0]) && (!$advList || strpos($id, 'minecraft:') !== 0)) {
        $advOthers[$id] = $s;
    }
}

$pageTitle = $name;
$nav = 'player';
$useCharts = true;
require APP_ROOT . '/templates/header.php';
?>

<section class="card profile">
  <div class="profile__skin">
    <img src="<?= h(body_url($uuid, 180)) ?>" alt="Skin de <?= h($name) ?>" height="200" onerror="this.hidden=true">
  </div>
  <div class="profile__main">
    <div class="profile__title">
      <?= head_img($uuid, 40, 'head profile__head') ?>
      <h1><?= h($name) ?></h1>
      <?php if ($isMe): ?><span class="you-badge">Vous</span><?php endif; ?>
      <?php if (App::cfg('server.enabled', true)): $isOnline = !empty($player['online']); ?>
      <span class="status-badge <?= $isOnline ? 'is-online' : 'is-offline' ?>" data-online-badge="<?= h($uuid) ?>"><span class="dot"></span><span data-badge-text><?=
        $isOnline ? 'En ligne' . ((int) $player['online_since'] > 0 ? ' depuis ' . h(fmt_duration(time() - (int) $player['online_since'])) : '') : 'Hors ligne'
      ?></span></span>
      <?php endif; ?>
    </div>
    <div class="profile__chips">
      <?php if ($pd): ?>
        <span class="chip chip--static"><?= mc_icon(['grass_block', 'command_block', 'map', 'ender_eye'][$pd['gamemode']] ?? 'grass_block') ?>Mode <?= h(GAMEMODES[$pd['gamemode']] ?? 'Survie') ?></span>
        <span class="chip chip--static"><?= mc_icon('experience_bottle') ?>Niveau <?= (int) $pd['xp_level'] ?></span>
      <?php endif; ?>
      <span class="chip chip--static"><?= mc_icon('knowledge_book') ?><?= $advDone ?> succès</span>
      <?php if (MapData::enabled() && ($player['pos_dim'] ?? '') !== '' && (!empty($player['online']) || MapData::showOffline())): ?>
        <a class="chip" href="carte.php?p=<?= h(rawurlencode($name)) ?>"><?= mc_icon('filled_map') ?>Voir sur la carte</a>
      <?php endif; ?>
    </div>
    <dl class="profile__meta">
      <div><dt>Première connexion</dt><dd><?= h(fmt_date($player['first_seen'])) ?></dd></div>
      <div><dt>Dernière connexion</dt><dd title="<?= h(fmt_datetime($player['last_seen'])) ?>"><?= !empty($player['online']) ? '<span class="text-online">En ce moment</span>' : h(fmt_ago($player['last_seen'])) ?></dd></div>
      <div><dt>Temps de jeu</dt><dd><?= h(fmt_ticks($player['play_time'])) ?></dd></div>
      <div><dt>UUID</dt><dd><button type="button" class="uuid" data-copy="<?= h($uuid) ?>" title="Copier l'UUID"><?= h($uuid) ?></button></dd></div>
    </dl>
  </div>
  <a class="profile__score" href="leaderboard.php?cat=score">
    <span class="profile__score-label">Score</span>
    <span class="profile__score-value"><?= h(fmt_int($player['score'])) ?></span>
    <span class="profile__score-rank"><?= rank_badge($ranks['score']) ?> sur <?= $total ?> joueur<?= $total > 1 ? 's' : '' ?></span>
  </a>
</section>

<div class="tabs" role="tablist" aria-label="Sections du profil" data-tabs>
  <button type="button" role="tab" data-tab="apercu" aria-selected="true">Aperçu</button>
  <button type="button" role="tab" data-tab="combat" aria-selected="false">Combat</button>
  <button type="button" role="tab" data-tab="minage" aria-selected="false">Minage &amp; artisanat</button>
  <button type="button" role="tab" data-tab="deplacements" aria-selected="false">Déplacements</button>
  <button type="button" role="tab" data-tab="succes" aria-selected="false">Succès</button>
  <?php if ($pd): ?><button type="button" role="tab" data-tab="inventaire" aria-selected="false"><?= $showInv ? 'Inventaire &amp; état' : 'État' ?></button><?php endif; ?>
  <?php if ($pluginsTab !== ''): ?><button type="button" role="tab" data-tab="perso" aria-selected="false"><?= h($pluginsTab) ?></button><?php endif; ?>
  <button type="button" role="tab" data-tab="stats" aria-selected="false">Toutes les stats</button>
</div>

<!-- Aperçu -->
<section class="panel stack" id="panel-apercu" role="tabpanel" aria-label="Aperçu">
  <div class="tiles">
    <?php foreach (array_keys(Stats::CATEGORIES) as $cat): ?>
      <?= stat_tile($cat, $player[$cat], $ranks[$cat], $total) ?>
    <?php endforeach; ?>
  </div>

  <?php if ($highlights): ?>
  <h2 class="h-section">Faits marquants</h2>
  <div class="highlights">
    <?php foreach ($highlights as $hl): ?>
    <div class="card highlight">
      <?= mc_icon($hl[3]) ?>
      <span class="highlight__text">
        <span class="highlight__label"><?= h($hl[0]) ?></span>
        <span class="highlight__name"><?= h($hl[1]) ?></span>
        <span class="highlight__value"><?= h($hl[2]) ?></span>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="cols-2">
    <div class="card card-pad">
      <div class="card__head"><h3>Temps de jeu par jour</h3><span class="muted">30 derniers jours</span></div>
      <?php if ($hasDaily): ?>
        <div class="chart"><canvas data-chart="<?= json_attr(['type' => 'bar', 'labels' => $dayLabels, 'values' => $dayHours, 'unit' => 'h']) ?>" role="img" aria-label="Temps de jeu par jour sur les 30 derniers jours"></canvas></div>
        <details class="chart-table"><summary>Voir les données</summary>
          <table class="table table--compact"><thead><tr><th>Jour</th><th class="num">Temps de jeu</th></tr></thead><tbody>
          <?php foreach ($dayHours as $i => $hv): if ($hv === null) continue; ?>
            <tr><td><?= h($dayLabels[$i]) ?></td><td class="num"><?= h(fmt_ticks($hv * 72000)) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </details>
      <?php else: ?>
        <p class="muted empty-note">L'historique se construit jour après jour : la progression apparaîtra dès demain.</p>
      <?php endif; ?>
    </div>
    <div class="card card-pad">
      <div class="card__head"><h3>Évolution du score</h3><span class="muted">30 derniers jours</span></div>
      <?php if ($hasScore): ?>
        <div class="chart"><canvas data-chart="<?= json_attr(['type' => 'line', 'labels' => $scoreLabels, 'values' => $scoreValues, 'unit' => 'pts']) ?>" role="img" aria-label="Évolution du score sur les 30 derniers jours"></canvas></div>
        <details class="chart-table"><summary>Voir les données</summary>
          <table class="table table--compact"><thead><tr><th>Jour</th><th class="num">Score</th></tr></thead><tbody>
          <?php foreach ($scoreValues as $i => $sv): ?>
            <tr><td><?= h($scoreLabels[$i]) ?></td><td class="num"><?= h(fmt_int($sv)) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </details>
      <?php else: ?>
        <p class="muted empty-note">Pas encore assez de jours enregistrés pour tracer une courbe.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Combat -->
<section class="panel stack" id="panel-combat" role="tabpanel" aria-label="Combat">
  <div class="tiles">
    <?php foreach (['mob_kills', 'player_kills', 'deaths', 'kd', 'damage_dealt', 'raids_won'] as $cat): ?>
      <?= stat_tile($cat, $player[$cat], $ranks[$cat], $total) ?>
    <?php endforeach; ?>
    <?= mini_tile('Dégâts subis', fmt_hearts($custom['damage_taken'] ?? 0), 'iron_chestplate') ?>
    <?= mini_tile('Bloqués au bouclier', fmt_hearts($custom['damage_blocked_by_shield'] ?? 0), 'shield') ?>
    <?= mini_tile('Depuis la dernière mort', fmt_ticks($custom['time_since_death'] ?? 0), 'totem_of_undying') ?>
    <?= mini_tile('Raids déclenchés', fmt_int($custom['raid_trigger'] ?? 0), 'ominous_bottle') ?>
  </div>
  <div class="cols-2">
    <div class="card card-pad">
      <div class="card__head"><h3>Mobs tués</h3><span class="muted"><?= count(array_filter($killed)) ?> espèces</span></div>
      <?= bar_list($killed, [Mc::class, 'entityName'], 'entity_icon', 'int', 12, 'Aucun mob tué.') ?>
    </div>
    <div class="card card-pad">
      <div class="card__head"><h3>Tué par</h3><span class="muted"><?= fmt_int($player['deaths']) ?> morts</span></div>
      <?= bar_list($killedBy, [Mc::class, 'entityName'], 'entity_icon', 'int', 12, 'Jamais tué par une créature.') ?>
    </div>
  </div>
</section>

<!-- Minage & artisanat -->
<section class="panel stack" id="panel-minage" role="tabpanel" aria-label="Minage et artisanat">
  <div class="tiles">
    <?php foreach (['blocks_mined', 'diamonds', 'ancient_debris', 'items_crafted', 'enchants'] as $cat): ?>
      <?= stat_tile($cat, $player[$cat], $ranks[$cat], $total) ?>
    <?php endforeach; ?>
    <?= mini_tile('Objets utilisés', fmt_compact(array_sum($used)), 'stone_pickaxe', fmt_int(array_sum($used))) ?>
    <?= mini_tile('Outils cassés', fmt_int(array_sum($broken)), 'wooden_pickaxe') ?>
  </div>
  <div class="cols-2">
    <div class="card card-pad">
      <div class="card__head"><h3>Blocs minés</h3><span class="muted"><?= count(array_filter($mined)) ?> types</span></div>
      <?= bar_list($mined, [Mc::class, 'blockName'], 'mc_icon', 'int', 12, 'Aucun bloc miné.') ?>
    </div>
    <div class="card card-pad">
      <div class="card__head"><h3>Objets fabriqués</h3><span class="muted"><?= count(array_filter($crafted)) ?> types</span></div>
      <?= bar_list($crafted, [Mc::class, 'itemName'], 'mc_icon', 'int', 12, 'Aucun objet fabriqué.') ?>
    </div>
    <div class="card card-pad">
      <div class="card__head"><h3>Objets utilisés</h3><span class="muted"><?= count(array_filter($used)) ?> types</span></div>
      <?= bar_list($used, [Mc::class, 'itemName'], 'mc_icon', 'int', 12, 'Aucun objet utilisé.') ?>
    </div>
    <div class="card card-pad">
      <div class="card__head"><h3>Objets ramassés</h3><span class="muted"><?= count(array_filter($pickedUp)) ?> types</span></div>
      <?= bar_list($pickedUp, [Mc::class, 'itemName'], 'mc_icon', 'int', 12, 'Aucun objet ramassé.') ?>
    </div>
    <div class="card card-pad">
      <div class="card__head"><h3>Outils cassés</h3></div>
      <?= bar_list($broken, [Mc::class, 'itemName'], 'mc_icon', 'int', 12, 'Aucun outil cassé.') ?>
    </div>
    <div class="card card-pad">
      <div class="card__head"><h3>Objets jetés</h3><span class="muted"><?= count(array_filter($dropped)) ?> types</span></div>
      <?= bar_list($dropped, [Mc::class, 'itemName'], 'mc_icon', 'int', 12, 'Aucun objet jeté.') ?>
    </div>
  </div>
</section>

<!-- Déplacements -->
<section class="panel stack" id="panel-deplacements" role="tabpanel" aria-label="Déplacements">
  <div class="tiles">
    <?= stat_tile('distance', $player['distance'], $ranks['distance'], $total) ?>
    <?= stat_tile('jumps', $player['jumps'], $ranks['jumps'], $total) ?>
    <?= mini_tile('Distance de chute', fmt_cm($custom['fall_one_cm'] ?? 0), 'feather') ?>
    <?= mini_tile('Temps accroupi', fmt_ticks($custom['sneak_time'] ?? 0), 'chainmail_leggings') ?>
    <?= mini_tile('Nuits dormies', fmt_int($custom['sleep_in_bed'] ?? 0), 'red_bed') ?>
  </div>
  <div class="card card-pad">
    <div class="card__head"><h3>Par moyen de déplacement</h3></div>
    <?= bar_list($moves, [Mc::class, 'statName'], $moveIcon, 'cm', 20, 'Aucun déplacement enregistré.') ?>
  </div>
</section>

<!-- Succès -->
<section class="panel stack" id="panel-succes" role="tabpanel" aria-label="Succès">
  <div class="card adv-summary">
    <?= mc_icon('knowledge_book', '', 'mc-icon--lg') ?>
    <div>
      <div class="adv-summary__value"><?= $advDone ?><?php if ($advTotal): ?> <span class="muted">/ <?= $advTotal ?></span><?php endif; ?></div>
      <div class="muted">succès obtenus · <?= rank_badge($ranks['advancements']) ?> du serveur</div>
    </div>
    <?php if ($advTotal): ?>
      <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $advTotal ?>" aria-valuenow="<?= $advDone ?>"><span style="width:<?= round($advDone / $advTotal * 100, 1) ?>%"></span></div>
      <strong><?= round($advDone / $advTotal * 100) ?> %</strong>
    <?php endif; ?>
  </div>

  <?php if ($advList): ?>
    <?php foreach (Mc::ADV_CATEGORIES as $catKey => $catLabel):
        $items = array_filter($advList, function ($a) use ($catKey) {
            return $a['cat'] === $catKey;
        });
        if (!$items) {
            continue;
        }
        $done = count(array_filter(array_keys($items), function ($id) use ($advState) {
            return !empty($advState[$id][0]);
        }));
    ?>
    <div class="card card-pad">
      <div class="card__head"><h3><?= h($catLabel) ?></h3><span class="muted"><?= $done ?> / <?= count($items) ?></span></div>
      <div class="adv-grid">
        <?php foreach ($items as $id => $meta): ?>
          <?= adv_card($id, $meta, $advState[$id] ?? null) ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($advOthers): ?>
  <div class="card card-pad">
    <div class="card__head"><h3><?= $advList ? 'Autres succès' : 'Succès obtenus' ?></h3><span class="muted"><?= count($advOthers) ?></span></div>
    <div class="adv-grid">
      <?php foreach ($advOthers as $id => $s): ?>
        <?= adv_card($id, null, $s) ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if ($pd): ?>
<!-- Inventaire & état -->
<section class="panel stack" id="panel-inventaire" role="tabpanel" aria-label="Inventaire et état">
  <?php if (!Auth::canSeePrivate()): ?>
  <?= locked_card('Connecte-toi pour voir l\'inventaire', 'player.php?p=' . rawurlencode($name)) ?>
  <?php else: ?>
  <div class="inv-layout">
    <?php if ($showInv): ?>
    <div class="stack">
      <div class="card card-pad">
        <div class="card__head"><h3>Inventaire</h3><span class="muted">au moment de la dernière sauvegarde</span></div>
        <div class="inv">
          <div class="inv__armor">
            <?= item_slot($pd['armor']['head'] ?? null, 'slot--armor', 'Casque') ?>
            <?= item_slot($pd['armor']['chest'] ?? null, 'slot--armor', 'Plastron') ?>
            <?= item_slot($pd['armor']['legs'] ?? null, 'slot--armor', 'Jambières') ?>
            <?= item_slot($pd['armor']['feet'] ?? null, 'slot--armor', 'Bottes') ?>
          </div>
          <div class="inv__main">
            <?= item_grid($pd['inventory'], 9, 27) ?>
            <div class="inv__sep"></div>
            <?= item_grid($pd['inventory'], 0, 9, (int) $pd['selected']) ?>
          </div>
          <div class="inv__offhand">
            <span class="inv__label">Main secondaire</span>
            <?= item_slot($pd['armor']['offhand'] ?? null, 'slot--armor', 'Main secondaire') ?>
          </div>
        </div>
      </div>
      <div class="card card-pad">
        <div class="card__head"><h3>Coffre de l'Ender</h3><span class="muted"><?= count($pd['ender']) ?> / 27 emplacements</span></div>
        <?= item_grid($pd['ender'], 0, 27) ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card card-pad state">
      <div class="card__head"><h3>État du joueur</h3></div>
      <div class="vitals">
        <?php $hp = min(1, $pd['health'] / max(1, $pd['max_health'])); ?>
        <div class="vital vital--health"><span class="vital__label">Vie</span><span class="vital__bar"><span style="width:<?= round($hp * 100) ?>%"></span></span><span class="vital__value"><?= h(fmt_dec($pd['health'] / 2, 1)) ?> / <?= h(fmt_dec($pd['max_health'] / 2, 0)) ?> ♥</span></div>
        <div class="vital vital--food"><span class="vital__label">Faim</span><span class="vital__bar"><span style="width:<?= round($pd['food'] / 20 * 100) ?>%"></span></span><span class="vital__value"><?= (int) $pd['food'] ?> / 20</span></div>
        <div class="vital vital--xp"><span class="vital__label">Niveau <?= (int) $pd['xp_level'] ?></span><span class="vital__bar"><span style="width:<?= round($pd['xp_progress'] * 100) ?>%"></span></span><span class="vital__value"><?= round($pd['xp_progress'] * 100) ?> %</span></div>
      </div>
      <dl class="kv">
        <dt>Mode de jeu</dt><dd><?= h(GAMEMODES[$pd['gamemode']] ?? 'Survie') ?></dd>
        <dt>Expérience totale</dt><dd><?= h(fmt_int($pd['xp_total'])) ?> points</dd>
        <dt>Dimension</dt><dd><?= h(Mc::dimensionName($pd['dimension'])) ?></dd>
        <?php if ($showPos): ?>
          <dt>Position</dt><dd><?= h(coords($pd['pos'])) ?></dd>
          <?php if (!empty($pd['spawn'])): ?><dt>Point de réapparition</dt><dd><?= h(coords($pd['spawn']['pos'])) ?> <span class="muted">(<?= h(Mc::dimensionName($pd['spawn']['dim'])) ?>)</span></dd><?php endif; ?>
          <?php if (!empty($pd['last_death'])): ?><dt>Dernière mort</dt><dd><?= h(coords($pd['last_death']['pos'])) ?> <span class="muted">(<?= h(Mc::dimensionName($pd['last_death']['dim'])) ?>)</span></dd><?php endif; ?>
        <?php endif; ?>
      </dl>
      <?php if (!empty($pd['effects'])): ?>
        <h4 class="h-sub">Effets actifs</h4>
        <ul class="effects">
          <?php foreach ($pd['effects'] as $e): ?>
            <li><span><?= h(Mc::effectName($e['id'])) ?><?= $e['amp'] > 0 ? ' ' . roman($e['amp'] + 1) : '' ?></span>
              <span class="muted"><?= $e['dur'] < 0 ? '∞' : h(sprintf('%d:%02d', intdiv(intdiv($e['dur'], 20), 60), intdiv($e['dur'], 20) % 60)) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($pluginsTab !== ''): ?>
<!-- Sac à dos & homes -->
<section class="panel stack" id="panel-perso" role="tabpanel" aria-label="<?= h($pluginsTab) ?>">
  <p class="private-note"><?= mc_icon('tripwire_hook') ?><span><?= $isMe ? 'Visible uniquement par vous et les admins du site.' : 'Visible uniquement par ' . h($name) . ' et les admins du site.' ?></span></p>
  <div class="perso-layout <?= $hasBackpack && $hasHomes ? 'inv-layout' : 'stack' ?>">
    <?php if ($hasBackpack): ?>
    <div class="card card-pad">
      <div class="card__head"><h3>Sac à dos</h3><?php if ($backpack): ?><span class="muted"><?= count($backpack['items']) ?> / <?= (int) $backpack['size'] ?> emplacements</span><?php endif; ?></div>
      <?php if ($backpack): ?>
        <?= item_grid($backpack['items'], 0, (int) $backpack['size']) ?>
        <?php if (!empty($backpack['updated'])): ?><p class="muted empty-note">Dernière sauvegarde le <?= h(fmt_date($backpack['updated'])) ?></p><?php endif; ?>
      <?php else: ?>
        <p class="muted empty-note">Aucun sac à dos enregistré.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($hasHomes): ?>
    <div class="card card-pad">
      <div class="card__head"><h3>Homes</h3><span class="muted"><?= count($homes) ?></span></div>
      <?php if ($homes): ?>
      <div class="table-wrap">
        <table class="table table--compact homes-table">
          <thead><tr><th>Nom</th><th>Monde</th><th>Coordonnées</th></tr></thead>
          <tbody>
          <?php foreach ($homes as $home): ?>
            <tr>
              <td class="strong"><?= h($home['name']) ?></td>
              <td><span class="homes-table__world"><?= $homeWorld($home['world']) ?></span></td>
              <td><?php if (isset($mapWorlds[$home['world']])): ?><a class="link-more" href="carte.php?<?= h(http_build_query(['w' => $home['world'], 'x' => $home['pos'][0], 'z' => $home['pos'][2]])) ?>" title="Voir sur la carte"><?= h(coords($home['pos'])) ?></a><?php else: ?><?= h(coords($home['pos'])) ?><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <p class="muted empty-note">Aucun home enregistré.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- Toutes les stats -->
<section class="panel stack" id="panel-stats" role="tabpanel" aria-label="Toutes les statistiques">
  <div class="toolbar">
    <input class="input" type="search" placeholder="Filtrer les statistiques…" data-filter-input="#all-stats" aria-label="Filtrer les statistiques">
  </div>
  <div class="card table-wrap">
    <table class="table stats-table" id="all-stats">
      <thead><tr><th>Statistique</th><th class="num">Valeur</th></tr></thead>
      <tbody>
        <?php
        $rows = [];
        foreach ($custom as $k => $v) {
            $rows[] = [Mc::statName($k), fmt_custom($k, $v)];
        }
        usort($rows, function ($a, $b) {
            return strcoll($a[0], $b[0]);
        });
        foreach ($rows as $r): ?>
          <tr data-filter-text="<?= h(mb_strtolower($r[0])) ?>"><td><?= h($r[0]) ?></td><td class="num strong"><?= h($r[1]) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted empty-note" data-filter-empty hidden>Aucune statistique ne correspond.</p>
</section>

<?php require APP_ROOT . '/templates/footer.php'; ?>

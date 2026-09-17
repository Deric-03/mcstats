<?php
/**
 * Page « Journal » de l'espace admin (incluse par admin.php, jamais appelée directement).
 * Un compte : ?log=<id> — tout le site : ?log=all.
 */
defined('APP_ROOT') || exit;

$which = (string) $_GET['log'];
$account = $which === 'all' ? null : Db::one('SELECT * FROM accounts WHERE id = ?', [(int) $which]);
if ($which !== 'all' && !$account) {
    $pageTitle = 'Journal';
    require APP_ROOT . '/templates/header.php';
    echo '<div class="auth-wrap"><div class="card empty"><p><strong>Compte introuvable.</strong></p>'
        . '<p class="muted"><a class="link-more" href="admin.php">Retour à l\'espace admin</a></p></div></div>';
    require APP_ROOT . '/templates/footer.php';
    exit;
}

if ($account && Auth::isProtected($account)) {
    http_response_code(403);
    $pageTitle = 'Journal';
    require APP_ROOT . '/templates/header.php';
    echo '<div class="auth-wrap"><div class="card empty"><p><strong>Journal réservé à l\'admin principal.</strong></p>'
        . '<p class="muted">Le compte de ' . h($account['username']) . " n'est pas consultable par les autres admins.</p>"
        . '<p class="muted"><a class="link-more" href="admin.php">Retour à l\'espace admin</a></p></div></div>';
    require APP_ROOT . '/templates/footer.php';
    exit;
}

$player = $account ? Repo::findPlayer($account['uuid'] !== '' ? $account['uuid'] : $account['username']) : null;
$name = $account ? $account['username'] : '';
// Listes paginées : 150 lignes par page par défaut
const LOG_SIZES = [50, 150, 500, 1000];
$per = (int) ($_GET['n'] ?? 150);
if (!in_array($per, LOG_SIZES, true)) {
    $per = 150;
}
// l'admin principal est masqué du journal du site pour les autres admins
$except = !$account && Auth::owner() && !Auth::isOwner() ? (string) Auth::owner()['username_lc'] : '';

$totalActions = $account ? Journal::countForAccount($account) : Journal::countRecent($except);
$totalEvents = !ServerLog::enabled() ? 0 : ($account ? ServerLog::countForPlayer($name) : ServerLog::countRecent($except));
$pagesActions = max(1, (int) ceil($totalActions / $per));
$pagesEvents = max(1, (int) ceil($totalEvents / $per));
$pa = min($pagesActions, max(1, (int) ($_GET['pa'] ?? 1)));
$pe = min($pagesEvents, max(1, (int) ($_GET['pe'] ?? 1)));

$actions = $account
    ? Journal::forAccount($account, $per, ($pa - 1) * $per)
    : Journal::recent($per, ($pa - 1) * $per, $except);
$events = !ServerLog::enabled() ? [] : ($account
    ? ServerLog::forPlayer($name, $per, ($pe - 1) * $per)
    : ServerLog::recent($per, ($pe - 1) * $per, $except));

/** Adresse de la page, en changeant un ou plusieurs paramètres. */
$logUrl = function (array $set = []) use ($which, $per, $pa, $pe) {
    $q = array_merge(['log' => $which, 'n' => $per, 'pa' => $pa, 'pe' => $pe], $set);
    return 'admin.php?' . http_build_query($q);
};

/** Barre au-dessus d'une liste : total, page, et choix du nombre de lignes. */
$logToolbar = function (int $total, int $page, int $pages, string $key) use ($per, $logUrl) {
    $html = '<div class="toolbar toolbar--log"><span class="muted">' . fmt_int($total) . ' ligne' . ($total > 1 ? 's' : '')
        . ($pages > 1 ? ' · page ' . $page . ' sur ' . $pages : '') . '</span><span class="perpage">Par page :';
    foreach (LOG_SIZES as $size) {
        $html .= $size === $per
            ? ' <strong class="perpage__on">' . $size . '</strong>'
            : ' <a class="link-more" href="' . h($logUrl(['n' => $size, $key => 1])) . '">' . $size . '</a>';
    }
    return $html . '</span></div>';
};
$sessions = $account && $player ? Journal::sessions($player['uuid'], 40) : [];
$week = $sessions ? Journal::timeSince($sessions, time() - 7 * 86400) : 0;
$logStatus = ServerLog::status();
$mapWorlds = MapData::enabled() ? MapData::worlds() : [];
$knownPlayers = [];
foreach (Db::all('SELECT uuid, name FROM players WHERE name <> \'\'') as $p) {
    $knownPlayers[mb_strtolower($p['name'])] = $p;
}

/** Détail d'un événement : mort (cause, auteur, lieu) ou simple message. */
$eventDetail = function (array $e) use ($mapWorlds, $knownPlayers) {
    if ($e['kind'] !== 'death') {
        return h($e['message']);
    }
    $html = '<span class="death">' . mc_icon(ServerLog::causeIcon($e['cause']))
        . '<strong>' . h(ServerLog::causeLabel($e['cause'])) . '</strong>';
    if ($e['killer'] !== '') {
        $lc = mb_strtolower($e['killer']);
        $id = str_replace(' ', '_', $lc);
        if (isset($knownPlayers[$lc])) {
            $who = head_img($knownPlayers[$lc]['uuid'], 18) . '<a class="link-more" href="' . h(player_url($knownPlayers[$lc])) . '">' . h($knownPlayers[$lc]['name']) . '</a>';
        } elseif (Mc::iconUrl(Mc::entityIconId($id)) !== null) {
            $who = entity_icon($id) . h(Mc::entityName($id));
        } else {
            $who = h($e['killer']);
        }
        $html .= '<span class="death__by">par ' . $who . '</span>';
    }
    if ($e['dim'] !== '') {
        $place = h(coords([$e['x'], $e['y'], $e['z']])) . ' <span class="muted">(' . h(Mc::dimensionName($e['dim'])) . ')</span>';
        $world = $mapWorlds ? MapData::worldFor($e['dim'], $mapWorlds) : null;
        $html .= '<span class="death__place">' . ($world
            ? '<a class="link-more" href="carte.php?' . h(http_build_query(['w' => $world, 'x' => (int) $e['x'], 'z' => (int) $e['z']])) . '" title="Voir sur la carte">' . $place . '</a>'
            : $place) . '</span>';
    }
    return $html . '</span><span class="death__raw muted">' . h($e['message']) . '</span>';
};

$pageTitle = $account ? 'Journal de ' . $name : 'Journal du site';
$nav = 'admin';
require APP_ROOT . '/templates/header.php';
?>
<div class="auth-wrap auth-wrap--wide">
  <div class="page-head page-head--row">
    <div>
      <h1><?= $account ? 'Journal de ' . h($name) : 'Journal du site' ?></h1>
      <?php if (!ServerLog::enabled()): ?>
        <p class="muted">Journal du serveur non configuré (section <code>server_log</code> de <code>config.php</code>)</p>
      <?php elseif (!empty($logStatus['error'])): ?>
        <p class="muted">Journal du serveur : <span class="text-danger"><?= h($logStatus['error']) ?></span></p>
      <?php endif; ?>
    </div>
    <a class="btn btn--sm btn--ghost" href="admin.php">← Espace admin</a>
  </div>

  <section class="stack">
    <h2 class="h-section">Actions du site</h2>
    <?= $logToolbar($totalActions, $pa, $pagesActions, 'pa') ?>
    <?php if (!$actions): ?>
      <div class="card empty"><p class="muted">Aucune action enregistrée.</p></div>
    <?php else: ?>
    <div class="card table-wrap">
      <table class="table table--compact">
        <thead><tr><th>Quand</th><?php if (!$account): ?><th>Joueur</th><?php endif; ?><th>Action</th><th>Par</th><th>Détail</th></tr></thead>
        <tbody>
        <?php foreach ($actions as $r): ?>
          <tr>
            <td class="muted" title="<?= h(fmt_datetime($r['created_at'])) ?>"><?= h(fmt_ago($r['created_at'])) ?></td>
            <?php if (!$account): ?><td><a class="link-more" href="admin.php?log=<?= (int) $r['account_id'] ?>"><?= h($r['username']) ?></a></td><?php endif; ?>
            <td class="<?= in_array($r['action'], Journal::SEVERE, true) ? 'text-danger' : 'strong' ?>"><?= h(Journal::label($r['action'])) ?></td>
            <td class="muted"><?= $r['actor'] !== '' ? h($r['actor']) : '<span title="Action du joueur lui-même">le joueur</span>' ?></td>
            <td class="muted log-detail"><?= h($r['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination($pa, $pagesActions, function ($p) use ($logUrl) {
        return $logUrl(['pa' => $p]);
    }) ?>
    <?php endif; ?>
  </section>

  <?php if (ServerLog::enabled()): ?>
  <section class="stack">
    <h2 class="h-section">Sur le serveur</h2>
    <?= $logToolbar($totalEvents, $pe, $pagesEvents, 'pe') ?>
    <div class="toolbar">
      <input class="input" type="search" placeholder="Filtrer la page affichée (mort, chat, commande…)" data-filter-input="#server-events" aria-label="Filtrer les événements">
    </div>
    <div class="card table-wrap">
      <table class="table table--compact" id="server-events">
        <thead><tr><th>Quand</th><?php if (!$account): ?><th>Joueur</th><?php endif; ?><th>Type</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach ($events as $e): ?>
          <tr data-filter-text="<?= h(mb_strtolower(ServerLog::label($e['kind']) . ' ' . $e['player'] . ' ' . $e['message'] . ' ' . ServerLog::causeLabel((string) $e['cause']) . ' ' . $e['killer'])) ?>">
            <td class="muted" title="<?= h(fmt_datetime($e['at'])) ?>"><?= h(fmt_ago($e['at'])) ?></td>
            <?php if (!$account): ?><td><?= h($e['player']) ?></td><?php endif; ?>
            <td class="event-kind"><?= mc_icon(ServerLog::icon($e['kind'])) ?><span><?= h(ServerLog::label($e['kind'])) ?></span></td>
            <td class="log-detail"><?= $eventDetail($e) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination($pe, $pagesEvents, function ($p) use ($logUrl) {
        return $logUrl(['pe' => $p]);
    }) ?>
    <?php if (!$events): ?><p class="muted empty-note">Rien pour le moment : les événements sont enregistrés au fil des synchronisations.</p><?php endif; ?>
    <p class="muted empty-note" data-filter-empty hidden>Aucun événement ne correspond.</p>
  </section>
  <?php endif; ?>

  <?php if ($account): ?>
  <section class="stack">
    <h2 class="h-section">Périodes de connexion <span class="muted"><?= $week ? h(fmt_duration($week)) . ' sur 7 jours' : '' ?></span></h2>
    <?php if (!$sessions): ?>
      <div class="card empty"><p class="muted">Aucune connexion enregistrée depuis la mise en place du journal.</p></div>
    <?php else: ?>
    <div class="card table-wrap">
      <table class="table table--compact">
        <thead><tr><th>Début</th><th>Fin</th><th>Durée</th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): $end = (int) $s['ended_at']; ?>
          <tr>
            <td><?= h(fmt_datetime($s['started_at'])) ?></td>
            <td class="muted"><?= $end ? h(fmt_datetime($end)) : '<span class="text-online">en ligne</span>' ?></td>
            <td class="strong"><?= h(fmt_duration(max(0, ($end ?: time()) - (int) $s['started_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>

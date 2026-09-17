<?php
require __DIR__ . '/src/web.php';

if (!Auth::enabled()) {
    header('Location: index.php');
    exit;
}
$me = Auth::user();
if (!$me) {
    header('Location: connexion.php?retour=admin.php');
    exit;
}

$pageTitle = 'Administration';
$nav = 'admin';

if (empty($me['is_admin'])) {
    http_response_code(403);
    require APP_ROOT . '/templates/header.php';
    echo '<div class="auth-wrap"><div class="card empty"><p><strong>Accès réservé aux admins.</strong></p></div></div>';
    require APP_ROOT . '/templates/footer.php';
    exit;
}

// ------------------------------------------------------------------ Actions
$flash = null;
$flashOk = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $target = $id ? Db::one('SELECT * FROM accounts WHERE id = ?', [$id]) : null;
    if (!Auth::checkCsrf()) {
        [$flashOk, $flash] = [false, 'La page a expiré : réessaie.'];
    } elseif (!$target) {
        [$flashOk, $flash] = [false, 'Compte introuvable.'];
    } elseif ((int) $target['id'] === (int) $me['id'] && in_array($action, ['disable', 'delete', 'refuse'], true)) {
        [$flashOk, $flash] = [false, 'Tu ne peux pas faire ça sur ton propre compte.'];
    } else {
        switch ($action) {
            case 'approve':
                $r = Whitelist::approve($id);
                [$flashOk, $flash] = [$r['ok'], $r['message']];
                break;
            case 'refuse':
                $r = Whitelist::refuse($id);
                [$flashOk, $flash] = [$r['ok'], $r['message']];
                break;
            case 'reset':
                $temp = Auth::resetPassword($id);
                $flash = "Mot de passe provisoire de {$target['username']} : $temp — transmets-le au joueur, il devra le changer à sa prochaine connexion.";
                break;
            case 'disable':
                Db::exec("UPDATE accounts SET status = 'disabled' WHERE id = ?", [$id]);
                Auth::dropSessions($id);
                $flash = "Le compte de {$target['username']} est désactivé. Il reste dans la whitelist du serveur.";
                break;
            case 'enable':
                Db::exec("UPDATE accounts SET status = 'active' WHERE id = ?", [$id]);
                $flash = "Le compte de {$target['username']} est réactivé.";
                break;
            case 'delete':
                Db::exec('DELETE FROM auth_sessions WHERE account_id = ?', [$id]);
                Db::exec('DELETE FROM accounts WHERE id = ?', [$id]);
                $flash = "Le compte de {$target['username']} est supprimé. Il reste dans la whitelist du serveur.";
                break;
            default:
                [$flashOk, $flash] = [false, 'Action inconnue.'];
        }
    }
}

// ------------------------------------------------------------------ Données
$pending = Db::all("SELECT * FROM accounts WHERE status = 'pending' ORDER BY created_at ASC");
$accounts = Db::all("SELECT * FROM accounts WHERE status <> 'pending' ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'disabled' THEN 1 ELSE 2 END, username_lc");
$serverWhitelist = $pending ? Whitelist::serverWhitelist() : null;
$played = [];
foreach (Db::all('SELECT uuid, name FROM players') as $p) {
    $played[$p['uuid']] = true;
    $played['name:' . mb_strtolower((string) $p['name'])] = true;
}
$hasPlayed = function (array $a) use ($played) {
    return ($a['uuid'] !== '' && isset($played[$a['uuid']])) || isset($played['name:' . $a['username_lc']]);
};
$rconState = Rcon::configured() ? ($pending && $serverWhitelist === null ? 'erreur' : 'actif') : 'off';
$actionForm = function (array $a, string $action, string $label, string $cls, string $confirm = '') {
    return '<form method="post" action="admin.php"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . Auth::csrfField()
        . '<input type="hidden" name="id" value="' . (int) $a['id'] . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<button class="btn btn--sm ' . h($cls) . '" type="submit">' . h($label) . '</button></form>';
};

require APP_ROOT . '/templates/header.php';
?>
<div class="auth-wrap auth-wrap--wide">
  <div class="page-head">
    <h1>Administration</h1>
    <p class="muted">
      <?php if ($rconState === 'actif'): ?>RCON actif : les joueurs validés sont ajoutés automatiquement à la whitelist.
      <?php elseif ($rconState === 'erreur'): ?><span class="text-danger">RCON configuré mais le serveur ne répond pas</span> : la validation échouera tant que le serveur Minecraft est éteint ou que RCON est désactivé.
      <?php else: ?>RCON non configuré : après validation, ajoute toi-même les joueurs à la whitelist (la commande est indiquée).<?php endif; ?>
    </p>
  </div>

  <?php if ($flash): ?><div class="alert <?= $flashOk ? 'alert--ok' : 'alert--error' ?>" role="status"><?= h($flash) ?></div><?php endif; ?>

  <section class="stack">
    <h2 class="h-section">Demandes de whitelist <span class="muted">(<?= count($pending) ?>)</span></h2>
    <?php if (!$pending): ?>
      <div class="card empty"><p class="muted">Aucune demande en attente.</p></div>
    <?php endif; ?>
    <?php foreach ($pending as $a): ?>
    <article class="card card-pad request">
      <div class="request__who">
        <?= head_img($a['uuid'] !== '' ? $a['uuid'] : $a['username'], 40) ?>
        <div>
          <strong class="request__name"><?= h($a['username']) ?></strong>
          <span class="request__tags">
            <span class="tag"><?= $a['edition'] === 'bedrock' ? 'Bedrock' : 'Java' ?></span>
            <?php if (empty($a['verified'])): ?><span class="tag tag--warn" title="GeyserMC ne connaît pas encore ce gamertag">Gamertag non vérifié</span><?php endif; ?>
            <?php if ($serverWhitelist !== null && isset($serverWhitelist[$a['username_lc']])): ?><span class="tag tag--ok">Déjà whitelisté</span><?php endif; ?>
            <?php if ($hasPlayed($a)): ?><span class="tag">A déjà joué sur le serveur</span><?php endif; ?>
          </span>
          <span class="muted request__date">Demande <?= h(fmt_ago($a['created_at'])) ?></span>
        </div>
      </div>
      <?php if ($a['message'] !== ''): ?><blockquote class="request__message"><?= nl2br(h($a['message'])) ?></blockquote><?php endif; ?>
      <div class="request__actions">
        <?= $actionForm($a, 'approve', 'Valider', 'btn--primary') ?>
        <?= $actionForm($a, 'refuse', 'Refuser', 'btn--danger', "Refuser la demande de {$a['username']} ?") ?>
        <span class="muted request__cmd">Commande : <code><?= h(Whitelist::commandFor($a)) ?></code></span>
      </div>
    </article>
    <?php endforeach; ?>
  </section>

  <section class="stack admin-accounts">
    <h2 class="h-section">Comptes <span class="muted">(<?= count($accounts) ?>)</span></h2>
    <?php if (!$accounts): ?>
      <div class="card empty"><p class="muted">Aucun compte pour le moment.</p></div>
    <?php else: ?>
    <div class="card table-wrap">
      <table class="table accounts-table">
        <thead><tr><th>Joueur</th><th>Statut</th><th class="hide-md">Membre depuis</th><th class="hide-md">Dernière connexion</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): $self = (int) $a['id'] === (int) $me['id']; ?>
          <tr>
            <td><span class="player-link"><?= head_img($a['uuid'] !== '' ? $a['uuid'] : $a['username'], 24) ?><span><?= h($a['username']) ?></span></span>
              <span class="tag"><?= $a['edition'] === 'bedrock' ? 'Bedrock' : 'Java' ?></span><?php if (!empty($a['is_admin'])): ?> <span class="tag tag--ok">Admin</span><?php endif; ?></td>
            <td><?= account_status_tag($a['status']) ?></td>
            <td class="hide-md muted"><?= h(fmt_date($a['decided_at'] ?: $a['created_at'])) ?></td>
            <td class="hide-md muted"><?= h($a['last_login'] ? fmt_ago($a['last_login']) : 'jamais') ?></td>
            <td class="accounts-table__actions">
              <?php if ($a['status'] === 'refused'): ?>
                <?= $actionForm($a, 'approve', 'Valider', 'btn--ghost') ?>
              <?php else: ?>
                <?= $actionForm($a, 'reset', 'Nouveau mot de passe', 'btn--ghost', "Générer un mot de passe provisoire pour {$a['username']} ? Il sera déconnecté.") ?>
                <?php if (!$self && $a['status'] === 'active'): ?><?= $actionForm($a, 'disable', 'Désactiver', 'btn--ghost', "Désactiver le compte de {$a['username']} ?") ?><?php endif; ?>
                <?php if ($a['status'] === 'disabled'): ?><?= $actionForm($a, 'enable', 'Réactiver', 'btn--ghost') ?><?php endif; ?>
              <?php endif; ?>
              <?php if (!$self): ?><?= $actionForm($a, 'delete', 'Supprimer', 'btn--danger', "Supprimer définitivement le compte de {$a['username']} ?") ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>

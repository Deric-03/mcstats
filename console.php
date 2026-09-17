<?php
/**
 * Terminal du serveur : envoie une commande par RCON et affiche la réponse.
 * Réservé à l'admin principal et aux admins à qui il a donné l'accès.
 */
require __DIR__ . '/src/web.php';

if (!Auth::enabled()) {
    header('Location: index.php');
    exit;
}
$me = Auth::user();
if (!$me) {
    header('Location: connexion.php?retour=console.php');
    exit;
}

$pageTitle = 'Terminal';
$nav = 'admin';

if (!Auth::canConsole()) {
    http_response_code(403);
    require APP_ROOT . '/templates/header.php';
    echo '<div class="auth-wrap"><div class="card empty"><p><strong>Terminal réservé.</strong></p>'
        . "<p class=\"muted\">Seul l'admin principal peut donner l'accès au terminal du serveur.</p>"
        . '<p class="muted"><a class="link-more" href="admin.php">Retour à l\'espace admin</a></p></div></div>';
    require APP_ROOT . '/templates/footer.php';
    exit;
}

// ------------------------------------------------------------------ Envoi d'une commande
$command = '';
$reply = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $command = trim((string) ($_POST['cmd'] ?? ''));
    $command = mb_substr((string) preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $command)), 0, 200);
    $command = ltrim($command, '/');
    $owner = Auth::ownerName();
    if (!Auth::checkCsrf()) {
        $error = 'La page a expiré : réessaie.';
    } elseif ($command === '') {
        $error = 'Tape une commande.';
    } elseif (!Rcon::configured()) {
        $error = "RCON n'est pas configuré : renseigne server.rcon_password dans config.php (voir le README).";
    } elseif (!Auth::isOwner() && $owner !== '' && preg_match('/(?<![A-Za-z0-9_])' . preg_quote($owner, '/') . '(?![A-Za-z0-9_])/i', $command)) {
        $error = "Cette commande vise l'admin principal : refusée.";
        // la tentative est gardée au journal : l'admin principal doit pouvoir la voir
        Journal::log('console', $me, (string) $me['username'], 'refusée (vise l\'admin principal) : ' . $command);
    } elseif (Auth::tooMany('console:' . $me['id'], 60, 60)) {
        $error = 'Trop de commandes d\'un coup : attends une minute.';
    } else {
        Auth::hit('console:' . $me['id']);
        try {
            $reply = Rcon::command($command);
        } catch (Throwable $e) {
            $error = 'Le serveur n\'a pas répondu : ' . $e->getMessage() . '.';
        }
        Journal::log('console', $me, (string) $me['username'], $command . ($reply !== null ? ' → ' . $reply : ' → ' . $error));
    }
}

// Historique : ses propres commandes, toutes celles du site pour l'admin principal
$history = Auth::isOwner()
    ? Db::all("SELECT * FROM admin_log WHERE action = 'console' ORDER BY created_at DESC, id DESC LIMIT 30")
    : Db::all("SELECT * FROM admin_log WHERE action = 'console' AND actor = ? ORDER BY created_at DESC, id DESC LIMIT 30", [$me['username']]);

require APP_ROOT . '/templates/header.php';
?>
<div class="auth-wrap auth-wrap--wide">
  <div class="page-head page-head--row">
    <div>
      <h1>Terminal du serveur</h1>
      <p class="muted">Les commandes sont envoyées par RCON, comme dans la console du serveur, et enregistrées au journal.</p>
    </div>
    <a class="btn btn--sm btn--ghost" href="admin.php">← Espace admin</a>
  </div>

  <?php if (!Rcon::configured()): ?>
    <div class="alert alert--error" role="alert">RCON n'est pas configuré : renseigne <code>server.rcon_password</code> dans <code>config.php</code>.</div>
  <?php endif; ?>

  <form class="card card-pad console" method="post" action="console.php">
    <?= Auth::csrfField() ?>
    <div class="console__out" role="log" aria-live="polite">
      <?php if ($error !== null): ?>
        <div class="console__line"><span class="console__prompt">&gt;</span> <span class="console__cmd"><?= h($command) ?></span></div>
        <div class="console__reply console__reply--error"><?= h($error) ?></div>
      <?php elseif ($reply !== null): ?>
        <div class="console__line"><span class="console__prompt">&gt;</span> <span class="console__cmd"><?= h($command) ?></span></div>
        <div class="console__reply"><?= $reply !== '' ? nl2br(h($reply)) : '<span class="muted">(aucune réponse)</span>' ?></div>
      <?php else: ?>
        <div class="muted">Prêt. Exemples : <code>list</code>, <code>time set day</code>, <code>whitelist list</code>, <code>say Bonjour</code>.</div>
      <?php endif; ?>
    </div>
    <div class="console__bar">
      <span class="console__prompt" aria-hidden="true">&gt;</span>
      <input class="input console__input" name="cmd" maxlength="200" autocomplete="off" spellcheck="false" autofocus
             placeholder="list" aria-label="Commande à envoyer au serveur">
      <button class="btn btn--primary" type="submit">Envoyer</button>
    </div>
  </form>

  <section class="stack">
    <h2 class="h-section"><?= Auth::isOwner() ? 'Dernières commandes du site' : 'Tes dernières commandes' ?> <span class="muted">(<?= count($history) ?>)</span></h2>
    <?php if (!$history): ?>
      <div class="card empty"><p class="muted">Aucune commande envoyée pour le moment.</p></div>
    <?php else: ?>
    <div class="card table-wrap">
      <table class="table table--compact">
        <thead><tr><th>Quand</th><?php if (Auth::isOwner()): ?><th>Par</th><?php endif; ?><th>Commande et réponse</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td class="muted" title="<?= h(fmt_datetime($h['created_at'])) ?>"><?= h(fmt_ago($h['created_at'])) ?></td>
            <?php if (Auth::isOwner()): ?><td class="muted"><?= h($h['actor']) ?></td><?php endif; ?>
            <td class="log-detail console__past"><?= h($h['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>

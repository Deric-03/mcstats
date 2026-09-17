<?php
require __DIR__ . '/src/web.php';

if (!Auth::enabled()) {
    header('Location: index.php');
    exit;
}
$me = Auth::user();
if (!$me) {
    header('Location: connexion.php?retour=compte.php');
    exit;
}

$error = null;
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf()) {
        $error = 'La page a expiré : réessaie.';
    } else {
        $error = Auth::changePassword($me, (string) ($_POST['actuel'] ?? ''), (string) ($_POST['nouveau'] ?? ''), (string) ($_POST['confirmation'] ?? ''));
        if ($error === null) {
            $success = 'Ton mot de passe a été changé. Tes autres appareils ont été déconnectés.';
            $me = Db::one('SELECT * FROM accounts WHERE id = ?', [$me['id']]);
        }
    }
}

// Profil de statistiques du joueur, s'il a déjà joué sur le serveur
$player = $me['uuid'] !== ''
    ? Db::one('SELECT uuid, name FROM players WHERE uuid = ? AND hidden = 0', [$me['uuid']])
    : Db::one('SELECT uuid, name FROM players WHERE LOWER(name) = ? AND hidden = 0', [$me['username_lc']]);

$pageTitle = 'Mon compte';
$nav = 'compte';
require APP_ROOT . '/templates/header.php';
?>
<div class="auth-wrap">
  <div class="page-head"><h1>Mon compte</h1></div>

  <?php if (!empty($me['must_change_password'])): ?>
    <div class="alert alert--info">Tu utilises un mot de passe provisoire : choisis-en un nouveau ci-dessous.</div>
  <?php endif; ?>

  <div class="card card-pad account-card">
    <?= head_img($me['uuid'] !== '' ? $me['uuid'] : $me['username'], 48) ?>
    <div class="account-card__body">
      <strong class="account-card__name"><?= h($me['username']) ?></strong>
      <span class="account-card__tags">
        <span class="tag"><?= $me['edition'] === 'bedrock' ? 'Bedrock' : 'Java' ?></span>
        <?php if (!empty($me['is_admin'])): ?><span class="tag tag--ok">Admin</span><?php endif; ?>
      </span>
      <span class="muted">Membre depuis le <?= h(fmt_date($me['decided_at'] ?: $me['created_at'])) ?></span>
    </div>
    <?php if ($player): ?>
      <a class="btn btn--ghost btn--sm" href="<?= h(player_url($player)) ?>">Voir mon profil</a>
    <?php endif; ?>
  </div>

  <form class="card card-pad form" method="post" action="compte.php">
    <h2 class="h-card">Changer de mot de passe</h2>
    <?= Auth::csrfField() ?>
    <?php if ($error): ?><div class="alert alert--error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert--ok" role="status"><?= h($success) ?></div><?php endif; ?>
    <div class="field">
      <label for="actuel"><?= !empty($me['must_change_password']) ? 'Mot de passe provisoire' : 'Mot de passe actuel' ?></label>
      <input class="input" id="actuel" name="actuel" type="password" required autocomplete="current-password">
    </div>
    <div class="field">
      <label for="nouveau">Nouveau mot de passe</label>
      <input class="input" id="nouveau" name="nouveau" type="password" minlength="<?= Auth::PASSWORD_MIN ?>" required autocomplete="new-password">
      <span class="hint">Au moins <?= Auth::PASSWORD_MIN ?> caractères.</span>
    </div>
    <div class="field">
      <label for="confirmation">Confirme le nouveau mot de passe</label>
      <input class="input" id="confirmation" name="confirmation" type="password" minlength="<?= Auth::PASSWORD_MIN ?>" required autocomplete="new-password">
    </div>
    <button class="btn btn--primary" type="submit">Changer le mot de passe</button>
  </form>

  <form method="post" action="deconnexion.php" class="logout">
    <?= Auth::csrfField() ?>
    <button class="btn btn--ghost" type="submit">Se déconnecter</button>
  </form>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>

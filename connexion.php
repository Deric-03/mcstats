<?php
require __DIR__ . '/src/web.php';

if (!Auth::enabled()) {
    header('Location: index.php');
    exit;
}

$return = safe_return($_POST['retour'] ?? ($_GET['retour'] ?? ''));
if (Auth::user()) {
    header('Location: ' . ($return !== '' ? $return : 'index.php'));
    exit;
}

$login = '';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string) ($_POST['pseudo'] ?? ''));
    if (!Auth::checkCsrf()) {
        $error = 'La page a expiré : réessaie.';
    } else {
        $error = Auth::login($login, (string) ($_POST['mdp'] ?? ''));
        if ($error === null) {
            $user = Auth::user();
            header('Location: ' . (!empty($user['must_change_password']) ? 'compte.php' : ($return !== '' ? $return : 'index.php')));
            exit;
        }
    }
}

$pageTitle = 'Connexion';
$nav = 'connexion';
require APP_ROOT . '/templates/header.php';
?>
<div class="auth-wrap">
  <div class="page-head">
    <h1>Connexion</h1>
    <p class="muted">Connecte-toi avec ton pseudo Minecraft et le mot de passe choisi lors de ta demande de whitelist.</p>
  </div>
  <form class="card card-pad form" method="post" action="connexion.php">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="retour" value="<?= h($return) ?>">
    <?php if ($error): ?><div class="alert alert--error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <div class="field">
      <label for="pseudo">Pseudo Minecraft</label>
      <input class="input" id="pseudo" name="pseudo" maxlength="40" required autocomplete="username" spellcheck="false" value="<?= h($login) ?>" autofocus>
      <span class="hint">Joueurs Bedrock : ton gamertag, avec ou sans le « <?= h(App::cfg('accounts.bedrock_prefix', '.')) ?> » devant.</span>
    </div>
    <div class="field">
      <label for="mdp">Mot de passe</label>
      <input class="input" id="mdp" name="mdp" type="password" required autocomplete="current-password">
    </div>
    <button class="btn btn--primary btn--block" type="submit">Se connecter</button>
    <p class="muted form__foot">Pas encore de compte ? <a class="link-more" href="demande.php">Demander la whitelist</a><br>
      Mot de passe oublié ? Demande à un admin du serveur de le réinitialiser.</p>
  </form>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>

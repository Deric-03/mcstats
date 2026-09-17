<?php
require __DIR__ . '/src/web.php';

if (!Auth::enabled()) {
    header('Location: index.php');
    exit;
}

$form = ['edition' => 'java', 'pseudo' => '', 'message' => ''];
$error = null;
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'edition' => (string) ($_POST['edition'] ?? 'java'),
        'pseudo'  => (string) ($_POST['pseudo'] ?? ''),
        'message' => (string) ($_POST['message'] ?? ''),
    ];
    if (!Auth::checkCsrf()) {
        $error = 'La page a expiré : réessaie.';
    } else {
        $result = Whitelist::request($form['edition'], $form['pseudo'], (string) ($_POST['mdp'] ?? ''), (string) ($_POST['mdp2'] ?? ''), $form['message']);
        if (!$result['ok']) {
            $error = $result['error'];
            $result = null;
        }
    }
}
$bedrock = Whitelist::bedrockEnabled();
$isBedrock = $bedrock && $form['edition'] === 'bedrock';
$address = (string) App::cfg('server.display_address', '');

$pageTitle = 'Demander la whitelist';
$nav = 'demande';
require APP_ROOT . '/templates/header.php';
?>
<div class="auth-wrap">
  <div class="page-head">
    <h1>Rejoindre le serveur</h1>
    <p class="muted">Demande la whitelist et crée ton compte en une seule fois. Ton compte sera actif dès que ta demande sera validée.</p>
  </div>

  <?php if ($result): $acc = $result['account']; ?>
  <div class="card card-pad stack">
    <div class="alert alert--ok"><strong>Demande envoyée !</strong></div>
    <p>Ta demande pour <strong><?= h($acc['username']) ?></strong> est en attente de validation. Dès qu'elle sera acceptée, tu pourras rejoindre le serveur et te connecter au site avec ce pseudo et ton mot de passe.</p>
    <?php if (!$result['verified']): ?>
      <div class="alert alert--info">Ton gamertag n'a pas pu être vérifié automatiquement (il n'est encore jamais passé sur un serveur Geyser) : l'admin le vérifiera.</div>
    <?php endif; ?>
    <?php if ($address !== ''): ?>
      <p class="muted">Adresse du serveur : <strong><?= h($address) ?></strong></p>
    <?php endif; ?>
    <a class="btn btn--ghost" href="index.php">Retour à l'accueil</a>
  </div>
  <?php else: ?>
  <form class="card card-pad form" method="post" action="demande.php">
    <?= Auth::csrfField() ?>
    <?php if ($error): ?><div class="alert alert--error" role="alert"><?= h($error) ?></div><?php endif; ?>

    <?php if ($bedrock): ?>
    <fieldset class="field">
      <legend class="field__label">Ton édition de Minecraft</legend>
      <div class="segmented" data-edition-switch>
        <label><input type="radio" name="edition" value="java"<?= $isBedrock ? '' : ' checked' ?>> Java <span class="muted">(PC)</span></label>
        <label><input type="radio" name="edition" value="bedrock"<?= $isBedrock ? ' checked' : '' ?>> Bedrock <span class="muted">(console, mobile)</span></label>
      </div>
    </fieldset>
    <?php endif; ?>

    <div class="field">
      <label for="pseudo" data-java="Pseudo Minecraft" data-bedrock="Gamertag Xbox"><?= $isBedrock ? 'Gamertag Xbox' : 'Pseudo Minecraft' ?></label>
      <input class="input" id="pseudo" name="pseudo" maxlength="16" required autocomplete="username" spellcheck="false" value="<?= h($form['pseudo']) ?>">
      <span class="hint" data-java="Le pseudo exact de ton compte Minecraft Java : il est vérifié auprès de Mojang." data-bedrock="Ton gamertag Xbox, tel qu'il apparaît dans Minecraft Bedrock."><?= $isBedrock ? "Ton gamertag Xbox, tel qu'il apparaît dans Minecraft Bedrock." : 'Le pseudo exact de ton compte Minecraft Java : il est vérifié auprès de Mojang.' ?></span>
    </div>

    <div class="field">
      <label for="mdp">Mot de passe pour le site</label>
      <input class="input" id="mdp" name="mdp" type="password" minlength="<?= Auth::PASSWORD_MIN ?>" required autocomplete="new-password">
      <span class="hint">Au moins <?= Auth::PASSWORD_MIN ?> caractères. Ce n'est pas le mot de passe de ton compte Minecraft : n'utilise pas le même.</span>
    </div>
    <div class="field">
      <label for="mdp2">Confirme le mot de passe</label>
      <input class="input" id="mdp2" name="mdp2" type="password" minlength="<?= Auth::PASSWORD_MIN ?>" required autocomplete="new-password">
    </div>

    <div class="field">
      <label for="message">Un mot pour l'admin <span class="muted">(facultatif)</span></label>
      <textarea class="input" id="message" name="message" maxlength="500" rows="3" placeholder="Qui es-tu ? Qui t'a parlé du serveur ?"><?= h($form['message']) ?></textarea>
    </div>

    <button class="btn btn--primary btn--block" type="submit">Envoyer ma demande</button>
    <p class="muted form__foot">Déjà un compte ? <a class="link-more" href="connexion.php">Se connecter</a></p>
  </form>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>

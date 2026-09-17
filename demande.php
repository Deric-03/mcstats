<?php
require __DIR__ . '/src/web.php';

if (!Auth::enabled()) {
    header('Location: index.php');
    exit;
}

$form = ['edition' => 'java', 'pseudo' => '', 'message' => ''];
$error = null;
$result = null;
$conflict = null;       // pseudo déjà pris : proposition de signalement
$reportSent = false;
$reportError = null;
$report = ['discord' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') {
    // Signalement d'usurpation
    $conflict = (string) ($_POST['pseudo'] ?? '');
    $report = ['discord' => (string) ($_POST['discord'] ?? ''), 'message' => (string) ($_POST['message'] ?? '')];
    if (!Auth::checkCsrf()) {
        $reportError = 'La page a expiré : réessaie.';
    } else {
        $r = Whitelist::report($conflict, $report['discord'], $report['message']);
        $reportSent = $r['ok'];
        $reportError = $r['error'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $conflict = $result['conflict'] ?? null;
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

  <?php if ($reportSent): ?>
  <div class="card card-pad stack">
    <div class="alert alert--ok"><strong>Signalement envoyé.</strong></div>
    <p>Un admin va vérifier le compte <strong><?= h($conflict) ?></strong> et te contactera sur Discord. S'il est révoqué, tu pourras refaire ta demande avec ce pseudo.</p>
    <a class="btn btn--ghost" href="index.php">Retour à l'accueil</a>
  </div>
  <?php elseif ($result): $acc = $result['account']; ?>
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

  <?php if ($conflict !== null): ?>
  <form class="card card-pad form report-box" method="post" action="demande.php">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="report">
    <input type="hidden" name="pseudo" value="<?= h($conflict) ?>">
    <div>
      <h2 class="h-card">Ce n'est pas toi ?</h2>
      <p class="muted">Si tu n'as pas créé le compte <strong><?= h($conflict) ?></strong>, quelqu'un a peut-être utilisé ton pseudo. Signale-le : un admin vérifiera et pourra révoquer ce compte.</p>
    </div>
    <?php if ($reportError): ?><div class="alert alert--error" role="alert"><?= h($reportError) ?></div><?php endif; ?>
    <div class="field">
      <label for="discord">Ton pseudo ou identifiant Discord</label>
      <input class="input" id="discord" name="discord" maxlength="37" required spellcheck="false" value="<?= h($report['discord']) ?>">
      <span class="hint">Pour que l'admin puisse te contacter et vérifier que c'est bien toi.</span>
    </div>
    <div class="field">
      <label for="report-message">Explication <span class="muted">(facultatif)</span></label>
      <textarea class="input" id="report-message" name="message" maxlength="500" rows="2"><?= h($report['message']) ?></textarea>
    </div>
    <button class="btn btn--danger btn--block" type="submit">Signaler une usurpation</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>

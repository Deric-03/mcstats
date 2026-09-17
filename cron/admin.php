<?php
/**
 * Gestion des admins du site en ligne de commande (réservé à qui a accès au serveur).
 *
 *   php cron/admin.php <pseudo>             active le compte et le rend admin
 *   php cron/admin.php <pseudo> --retirer   retire les droits admin
 *   php cron/admin.php --liste              liste les comptes admin
 *
 * Le compte doit d'abord exister : fais ta demande de whitelist sur le site, puis lance cette commande.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script se lance en ligne de commande.');
}
require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
if (!$args || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage : php cron/admin.php <pseudo> [--retirer] | --liste\n";
    exit($args ? 0 : 1);
}
if (in_array('--liste', $args, true)) {
    $admins = Db::all('SELECT username, status FROM accounts WHERE is_admin = 1 ORDER BY username_lc');
    echo $admins ? implode(PHP_EOL, array_map(function ($a) {
        return $a['username'] . ' (' . (Whitelist::STATUS_LABELS[$a['status']] ?? $a['status']) . ')';
    }, $admins)) . PHP_EOL : "Aucun admin.\n";
    exit(0);
}

$pseudo = '';
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) {
        $pseudo = $a;
    }
}
$account = Auth::findByLogin($pseudo);
if (!$account) {
    fwrite(STDERR, "Aucun compte « $pseudo ». Fais d'abord la demande de whitelist sur le site.\n");
    exit(1);
}
if (in_array('--retirer', $args, true)) {
    Db::exec('UPDATE accounts SET is_admin = 0 WHERE id = ?', [$account['id']]);
    echo "{$account['username']} n'est plus admin.\n";
    exit(0);
}
Db::exec("UPDATE accounts SET is_admin = 1, status = 'active', decided_at = CASE WHEN decided_at = 0 THEN ? ELSE decided_at END WHERE id = ?", [time(), $account['id']]);
echo "{$account['username']} est maintenant admin et son compte est actif.\n";
if ($account['status'] === 'pending') {
    echo "Pense à l'ajouter à la whitelist du serveur si ce n'est pas déjà fait : " . Whitelist::commandFor($account) . "\n";
}

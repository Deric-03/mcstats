<?php
/**
 * Comptes du site : connexion, sessions, protection CSRF et limitation des tentatives.
 *
 * Un compte porte le pseudo Minecraft du joueur. Il est créé par une demande de whitelist (statut
 * « pending ») et devient actif quand un admin valide la demande (voir Whitelist).
 * La session est un jeton aléatoire en cookie, dont seule l'empreinte est stockée en base.
 */
final class Auth
{
    const COOKIE = 'mcstats_auth';
    const CSRF_COOKIE = 'mcstats_csrf';
    const SESSION_DAYS = 30;
    const PASSWORD_MIN = 8;

    /** @var array|null|false false = pas encore cherché */
    private static $user = false;

    public static function enabled(): bool
    {
        return (bool) App::cfg('accounts.enabled', false);
    }

    /** Compte connecté et actif, ou null. */
    public static function user(): ?array
    {
        if (self::$user !== false) {
            return self::$user;
        }
        self::$user = null;
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!self::enabled() || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $row = Db::one(
            "SELECT a.* FROM auth_sessions s JOIN accounts a ON a.id = s.account_id
             WHERE s.token_hash = ? AND s.expires_at > ? AND a.status = 'active'",
            [hash('sha256', $token), time()]
        );
        self::$user = $row ?: null;
        return self::$user;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && !empty($u['is_admin']);
    }

    /** Le profil de ce joueur appartient-il au compte connecté ? */
    public static function owns(array $player): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['uuid'] !== '') {
            return strtolower($u['uuid']) === strtolower((string) $player['uuid']);
        }
        // gamertag Bedrock non vérifié : pas d'UUID, on compare le pseudo
        return (string) $player['name'] !== '' && mb_strtolower((string) $player['name']) === $u['username_lc'];
    }

    /** Carte, positions et inventaires : réservés aux joueurs connectés quand les comptes sont activés. */
    public static function canSeePrivate(): bool
    {
        return !self::enabled() || self::user() !== null;
    }

    public static function pendingCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM accounts WHERE status = 'pending'");
    }

    // ---------------------------------------------------------------- Connexion

    /** @return string|null message d'erreur, null si la connexion a réussi */
    public static function login(string $login, string $password): ?string
    {
        $ip = self::ip();
        $key = mb_strtolower(trim($login));
        if (self::tooMany('login-ip:' . $ip, 20, 900) || self::tooMany('login-user:' . $key, 5, 900)) {
            return 'Trop de tentatives de connexion. Réessaie dans 15 minutes.';
        }
        $acc = self::findByLogin($login);
        // password_verify est appelé même sans compte, pour ne pas révéler par le temps de réponse qu'un pseudo existe
        $ok = password_verify($password, $acc['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuO3aF6k0oBZ7j9Ic4oQOEYQpD5fKmPVa');
        if (!$acc || !$ok) {
            self::hit('login-ip:' . $ip);
            self::hit('login-user:' . $key);
            return 'Pseudo ou mot de passe incorrect.';
        }
        switch ($acc['status']) {
            case 'pending':
                return "Ta demande de whitelist n'a pas encore été validée. Tu pourras te connecter dès qu'elle le sera.";
            case 'refused':
                return 'Ta demande de whitelist a été refusée.';
            case 'disabled':
                return 'Ce compte est désactivé.';
        }
        if (password_needs_rehash($acc['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE accounts SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $acc['id']]);
        }
        Db::exec('UPDATE accounts SET last_login = ? WHERE id = ?', [time(), $acc['id']]);
        self::startSession((int) $acc['id']);
        self::$user = $acc;
        return null;
    }

    public static function logout(): void
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $token)) {
            Db::exec('DELETE FROM auth_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        }
        self::setCookie(self::COOKIE, '', time() - 3600);
        self::$user = null;
    }

    /** Recherche par pseudo ; pour Bedrock, le préfixe Floodgate est facultatif. */
    public static function findByLogin(string $login): ?array
    {
        $login = trim($login);
        $acc = Db::one('SELECT * FROM accounts WHERE username_lc = ?', [mb_strtolower($login)]);
        $prefix = (string) App::cfg('accounts.bedrock_prefix', '.');
        if (!$acc && $prefix !== '' && strpos($login, $prefix) !== 0) {
            $acc = Db::one(
                "SELECT * FROM accounts WHERE username_lc = ? AND edition = 'bedrock'",
                [mb_strtolower($prefix . str_replace(' ', '_', $login))]
            );
        }
        return $acc ?: null;
    }

    private static function startSession(int $accountId): void
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + self::SESSION_DAYS * 86400;
        Db::exec('DELETE FROM auth_sessions WHERE expires_at < ?', [$now]);
        Db::exec('INSERT INTO auth_sessions (token_hash, account_id, created_at, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $token), $accountId, $now, $expires]);
        self::setCookie(self::COOKIE, $token, $expires);
    }

    /** Déconnecte toutes les sessions d'un compte (mot de passe changé, compte désactivé…). */
    public static function dropSessions(int $accountId, bool $keepCurrent = false): void
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($keepCurrent && preg_match('/^[0-9a-f]{64}$/', $token)) {
            Db::exec('DELETE FROM auth_sessions WHERE account_id = ? AND token_hash <> ?', [$accountId, hash('sha256', $token)]);
        } else {
            Db::exec('DELETE FROM auth_sessions WHERE account_id = ?', [$accountId]);
        }
    }

    // ---------------------------------------------------------------- Mots de passe

    /** @return string|null message d'erreur */
    public static function checkNewPassword(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            return 'Le mot de passe doit faire au moins ' . self::PASSWORD_MIN . ' caractères.';
        }
        if (strlen($password) > 200) {
            return 'Le mot de passe est trop long.';
        }
        if (!hash_equals($password, $confirm)) {
            return 'Les deux mots de passe ne correspondent pas.';
        }
        return null;
    }

    /** @return string|null message d'erreur */
    public static function changePassword(array $account, string $current, string $new, string $confirm): ?string
    {
        if (!password_verify($current, $account['password_hash'])) {
            return 'Mot de passe actuel incorrect.';
        }
        if ($error = self::checkNewPassword($new, $confirm)) {
            return $error;
        }
        Db::exec('UPDATE accounts SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $account['id']]);
        self::dropSessions((int) $account['id'], true);
        return null;
    }

    /** Mot de passe provisoire (réinitialisation par un admin), à changer à la prochaine connexion. */
    public static function resetPassword(int $accountId): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $temp = '';
        for ($i = 0; $i < 12; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        Db::exec('UPDATE accounts SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($temp, PASSWORD_DEFAULT), $accountId]);
        self::dropSessions($accountId);
        return $temp;
    }

    // ---------------------------------------------------------------- CSRF

    /** À appeler avant tout affichage : pose le cookie du jeton si besoin. */
    public static function csrfToken(): string
    {
        static $token = null;
        if ($token === null) {
            $token = (string) ($_COOKIE[self::CSRF_COOKIE] ?? '');
            if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
                $token = bin2hex(random_bytes(16));
                self::setCookie(self::CSRF_COOKIE, $token, 0);
                $_COOKIE[self::CSRF_COOKIE] = $token;
            }
        }
        return $token;
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . h(self::csrfToken()) . '">';
    }

    public static function checkCsrf(): bool
    {
        $cookie = (string) ($_COOKIE[self::CSRF_COOKIE] ?? '');
        $posted = $_POST['csrf'] ?? '';
        return is_string($posted) && preg_match('/^[0-9a-f]{32}$/', $cookie) && hash_equals($cookie, $posted);
    }

    // ---------------------------------------------------------------- Limitation des tentatives

    public static function tooMany(string $key, int $max, int $window): bool
    {
        return (int) Db::value('SELECT COUNT(*) FROM auth_attempts WHERE k = ? AND at > ?', [$key, time() - $window]) >= $max;
    }

    public static function hit(string $key): void
    {
        Db::exec('INSERT INTO auth_attempts (k, at) VALUES (?, ?)', [mb_substr($key, 0, 120), time()]);
        if (random_int(1, 50) === 1) {
            Db::exec('DELETE FROM auth_attempts WHERE at < ?', [time() - 86400]);
        }
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

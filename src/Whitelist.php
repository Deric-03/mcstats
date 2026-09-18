<?php
/**
 * Demandes de whitelist : vérification des pseudos (Mojang pour Java, GeyserMC pour Bedrock),
 * création du compte en attente, validation ou refus par un admin (ajout à la whitelist via RCON).
 */
final class Whitelist
{
    const STATUS_LABELS = [
        'pending'  => 'En attente',
        'active'   => 'Actif',
        'refused'  => 'Refusé',
        'disabled' => 'Désactivé',
    ];

    public static function bedrockEnabled(): bool
    {
        return (bool) App::cfg('accounts.bedrock', true);
    }

    // ---------------------------------------------------------------- Vérification des pseudos

    /**
     * Profil Java.
     * @return array|false|null ['name' => pseudo exact, 'uuid' => …], false si le compte n'existe pas, null si Mojang ne répond pas
     */
    public static function javaProfile(string $name)
    {
        [$code, $body] = http_fetch('https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($name));
        if ($code === 404 || $code === 204) {
            return false;
        }
        $j = $code === 200 ? json_decode($body, true) : null;
        if (!isset($j['id'], $j['name']) || !preg_match('/^[0-9a-f]{32}$/', $j['id'])) {
            return null;
        }
        $h = $j['id'];
        return [
            'name' => (string) $j['name'],
            'uuid' => substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20),
        ];
    }

    /**
     * Profil Bedrock via l'API de GeyserMC, qui ne connaît que les joueurs déjà passés sur un serveur Geyser.
     * @return array|string|false|null ['xuid', 'uuid'] ; 'unknown' si GeyserMC ne connaît pas encore ce gamertag ;
     *                                  false si le gamertag est invalide ; null si l'API ne répond pas
     */
    public static function bedrockProfile(string $gamertag)
    {
        [$code, $body] = http_fetch('https://api.geysermc.org/v2/xbox/xuid/' . rawurlencode($gamertag));
        $j = json_decode($body, true);
        if ($code === 200 && isset($j['xuid']) && is_numeric($j['xuid'])) {
            $x = (int) $j['xuid'];
            // UUID Floodgate : 00000000-0000-0000-XXXX-XXXXXXXXXXXX (xuid en hexadécimal)
            return ['xuid' => (string) $j['xuid'], 'uuid' => sprintf('00000000-0000-0000-%04x-%012x', ($x >> 48) & 0xFFFF, $x & 0xFFFFFFFFFFFF)];
        }
        if ($code === 400) {
            return false;
        }
        if (($code === 503 || $code === 404) && stripos((string) ($j['message'] ?? ''), 'unable to find') !== false) {
            return 'unknown';
        }
        return null;
    }

    // ---------------------------------------------------------------- Demande

    /**
     * Crée la demande de whitelist et le compte en attente.
     * @return array ['ok' => bool, 'error' => ?string, 'account' => ?array, 'verified' => bool]
     */
    public static function request(string $edition, string $pseudo, string $password, string $confirm, string $message): array
    {
        $fail = function (string $error) {
            return ['ok' => false, 'error' => $error, 'account' => null, 'verified' => false];
        };
        if (!Auth::enabled()) {
            return $fail('Les demandes de whitelist ne sont pas ouvertes.');
        }
        $edition = $edition === 'bedrock' && self::bedrockEnabled() ? 'bedrock' : 'java';
        $pseudo = trim(preg_replace('/\s+/', ' ', $pseudo));
        $message = mb_substr(trim($message), 0, 500);

        if ($edition === 'java' && !preg_match('/^[A-Za-z0-9_]{2,16}$/', $pseudo)) {
            return $fail('Pseudo Java invalide : 2 à 16 caractères, lettres, chiffres ou « _ ».');
        }
        if ($edition === 'bedrock' && !preg_match('/^[A-Za-z0-9 ]{1,16}$/', $pseudo)) {
            return $fail('Gamertag invalide : 1 à 16 caractères, lettres, chiffres ou espaces.');
        }
        if ($error = Auth::checkNewPassword($password, $confirm)) {
            return $fail($error);
        }
        $ipKey = 'request-ip:' . Auth::ip();
        if (Auth::tooMany($ipKey, 5, 3600)) {
            return $fail('Trop de demandes depuis ta connexion. Réessaie dans une heure.');
        }
        Auth::hit($ipKey);

        $verified = true;
        if ($edition === 'java') {
            $profile = self::javaProfile($pseudo);
            if ($profile === false) {
                return $fail("Aucun compte Minecraft Java ne porte le pseudo « $pseudo ». Vérifie l'orthographe.");
            }
            if ($profile === null) {
                return $fail("Impossible de vérifier le pseudo auprès de Mojang pour le moment. Réessaie dans quelques minutes.");
            }
            $username = $profile['name'];
            $uuid = $profile['uuid'];
        } else {
            $profile = self::bedrockProfile($pseudo);
            if ($profile === false) {
                return $fail('Gamertag Xbox invalide.');
            }
            if ($profile === null) {
                return $fail("Impossible de vérifier le gamertag pour le moment. Réessaie dans quelques minutes.");
            }
            $verified = is_array($profile);
            $username = App::cfg('accounts.bedrock_prefix', '.') . str_replace(' ', '_', $pseudo);
            $uuid = $verified ? $profile['uuid'] : '';
        }

        $existing = Db::one('SELECT * FROM accounts WHERE username_lc = ?', [mb_strtolower($username)]);
        if (!$existing && $uuid !== '') {
            $existing = Db::one('SELECT * FROM accounts WHERE uuid = ?', [$uuid]);
        }
        // 'conflict' : permet à la page de proposer le signalement d'une usurpation
        if ($existing && $existing['status'] === 'pending') {
            return ['conflict' => $existing['username']] + $fail('Une demande est déjà en attente pour ce joueur.');
        }
        if ($existing && $existing['status'] !== 'refused') {
            return ['conflict' => $existing['username']] + $fail('Un compte existe déjà pour ce joueur : connecte-toi.');
        }

        $now = time();
        $values = [$username, mb_strtolower($username), $edition, $uuid, $verified ? 1 : 0, password_hash($password, PASSWORD_DEFAULT), $message, $now];
        if ($existing) {
            // nouvelle demande après un refus
            Db::exec(
                "UPDATE accounts SET username = ?, username_lc = ?, edition = ?, uuid = ?, verified = ?, password_hash = ?, message = ?,
                 created_at = ?, status = 'pending', decided_at = 0, admin_note = '', must_change_password = 0 WHERE id = ?",
                array_merge($values, [$existing['id']])
            );
        } else {
            Db::exec(
                "INSERT INTO accounts (username, username_lc, edition, uuid, verified, password_hash, message, created_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                $values
            );
        }
        $account = Db::one('SELECT * FROM accounts WHERE username_lc = ?', [mb_strtolower($username)]);
        Journal::log('request', $account, '', $message);
        return ['ok' => true, 'error' => null, 'account' => $account, 'verified' => $verified];
    }

    // ---------------------------------------------------------------- Signalement d'usurpation

    /**
     * Un joueur signale qu'un compte a été créé avec son pseudo. Le compte reste actif :
     * il est mis en avant dans l'espace admin, qui le révoque ou ignore le signalement.
     * @return array ['ok' => bool, 'error' => ?string]
     */
    public static function report(string $username, string $discord, string $message): array
    {
        $fail = function (string $error) {
            return ['ok' => false, 'error' => $error];
        };
        $discord = trim($discord);
        $message = mb_substr(trim($message), 0, 500);
        $acc = Db::one('SELECT id, status FROM accounts WHERE username_lc = ?', [mb_strtolower(trim($username))]);
        if (!$acc || $acc['status'] === 'refused') {
            return $fail("Aucun compte n'utilise ce pseudo : tu peux faire ta demande normalement.");
        }
        // pseudo Discord (nouveau format ou ancien avec #1234) ou identifiant numérique
        if (!preg_match('/^(?:\d{17,20}|[A-Za-z0-9_.]{2,32}(?:#\d{4})?)$/', $discord)) {
            return $fail('Indique ton pseudo Discord ou ton identifiant Discord, pour que l\'admin puisse te contacter.');
        }
        $key = 'report-ip:' . Auth::ip();
        if (Auth::tooMany($key, 3, 3600)) {
            return $fail('Trop de signalements depuis ta connexion. Réessaie dans une heure.');
        }
        Auth::hit($key);
        Db::exec(
            "INSERT INTO identity_reports (account_id, discord, message, ip, status, created_at) VALUES (?, ?, ?, ?, 'open', ?)",
            [$acc['id'], $discord, $message, Auth::ip(), time()]
        );
        Journal::log('report', Db::one('SELECT * FROM accounts WHERE id = ?', [$acc['id']]) ?: [], '', "Discord : $discord" . ($message !== '' ? " — $message" : ''));
        return ['ok' => true, 'error' => null];
    }

    /** Signalements en cours, regroupés par compte : account_id => [signalements…]. */
    public static function openReports(): array
    {
        $out = [];
        foreach (Db::all("SELECT * FROM identity_reports WHERE status = 'open' ORDER BY created_at DESC") as $r) {
            $out[(int) $r['account_id']][] = $r;
        }
        return $out;
    }

    public static function openReportCount(): int
    {
        return (int) Db::value("SELECT COUNT(DISTINCT account_id) FROM identity_reports WHERE status = 'open'");
    }

    /** Clôt les signalements en cours d'un compte ('revoked' ou 'dismissed'). */
    public static function closeReports(int $accountId, string $status): void
    {
        Db::exec("UPDATE identity_reports SET status = ?, decided_at = ? WHERE account_id = ? AND status = 'open'", [$status, time(), $accountId]);
    }

    // ---------------------------------------------------------------- Décision de l'admin

    /** Commande de whitelist à envoyer au serveur pour ce compte. */
    public static function commandFor(array $account): string
    {
        if ($account['edition'] === 'bedrock') {
            $prefix = (string) App::cfg('accounts.bedrock_prefix', '.');
            $gamertag = $prefix !== '' && strpos($account['username'], $prefix) === 0 ? substr($account['username'], strlen($prefix)) : $account['username'];
            return strtr((string) App::cfg('accounts.whitelist_bedrock', 'fwhitelist add {gamertag}'), ['{gamertag}' => $gamertag, '{name}' => $account['username']]);
        }
        return strtr((string) App::cfg('accounts.whitelist_java', 'whitelist add {name}'), ['{name}' => $account['username']]);
    }

    /**
     * Valide la demande : ajout à la whitelist (RCON si configuré) puis activation du compte.
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function approve(int $id): array
    {
        $acc = Db::one('SELECT * FROM accounts WHERE id = ?', [$id]);
        if (!$acc) {
            return ['ok' => false, 'message' => 'Compte introuvable.'];
        }
        $command = self::commandFor($acc);
        if (Rcon::configured()) {
            try {
                $reply = Rcon::command($command);
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => "La demande de {$acc['username']} n'a pas été validée : " . $e->getMessage() . '.'];
            }
            if (preg_match('/(does not exist|unknown|not found|introuvable|error|erreur|usage)/i', $reply)) {
                return ['ok' => false, 'message' => "Le serveur a refusé « $command » : $reply"];
            }
            $note = $reply !== '' ? $reply : "« $command » envoyé";
            $message = "{$acc['username']} est validé. Réponse du serveur : " . ($reply !== '' ? $reply : '(aucune)');
        } else {
            $note = 'Whitelist à faire à la main';
            $message = "{$acc['username']} est validé. RCON n'est pas configuré : tape « $command » dans la console du serveur.";
        }
        Db::exec("UPDATE accounts SET status = 'active', decided_at = ?, admin_note = ? WHERE id = ?", [time(), mb_substr($note, 0, 500), $id]);
        return ['ok' => true, 'message' => $message];
    }

    public static function refuse(int $id): array
    {
        $acc = Db::one('SELECT * FROM accounts WHERE id = ?', [$id]);
        if (!$acc) {
            return ['ok' => false, 'message' => 'Compte introuvable.'];
        }
        Db::exec("UPDATE accounts SET status = 'refused', decided_at = ? WHERE id = ?", [time(), $id]);
        Auth::dropSessions($id);
        self::closeReports($id, 'revoked');
        return ['ok' => true, 'message' => "La demande de {$acc['username']} est refusée."];
    }

    /** Commande qui retire un compte de la whitelist du serveur. */
    public static function removeCommandFor(array $account): string
    {
        if ($account['edition'] === 'bedrock') {
            $prefix = (string) App::cfg('accounts.bedrock_prefix', '.');
            $gamertag = $prefix !== '' && strpos($account['username'], $prefix) === 0 ? substr($account['username'], strlen($prefix)) : $account['username'];
            return strtr((string) App::cfg('accounts.unwhitelist_bedrock', 'fwhitelist remove {gamertag}'), ['{gamertag}' => $gamertag, '{name}' => $account['username']]);
        }
        return strtr((string) App::cfg('accounts.unwhitelist_java', 'whitelist remove {name}'), ['{name}' => $account['username']]);
    }

    /**
     * Met la whitelist du serveur en accord avec le compte : ajout ($add) ou retrait.
     * L'action sur le site a lieu dans tous les cas ; si le serveur ne répond pas, le message
     * donne la commande à taper à la main.
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function follow(array $account, bool $add): array
    {
        $command = $add ? self::commandFor($account) : self::removeCommandFor($account);
        if (!Rcon::configured()) {
            return ['ok' => false, 'message' => "RCON n'est pas configuré : tape « $command » dans la console du serveur."];
        }
        try {
            $reply = Rcon::command($command);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => "Whitelist non modifiée (" . $e->getMessage() . ") : tape « $command » dans la console du serveur."];
        }
        if (preg_match('/(does not exist|unknown|introuvable|error|erreur|usage)/i', $reply)) {
            return ['ok' => false, 'message' => "Le serveur a refusé « $command » : $reply"];
        }
        return ['ok' => true, 'message' => ($add ? 'Ajouté à la whitelist du serveur' : 'Retiré de la whitelist du serveur')
            . ($reply !== '' ? " ($reply)." : '.')];
    }

    /** Pseudos déjà présents dans la whitelist du serveur (en minuscules), ou null si RCON indisponible. */
    /** Commande serveur pour un compte : {name} = pseudo exact (préfixe Bedrock compris), {reason} = motif. */
    private static function serverCommand(string $key, string $default, array $account, string $reason = ''): string
    {
        $cmd = strtr((string) App::cfg("accounts.$key", $default), ['{name}' => $account['username'], '{reason}' => $reason]);
        return trim((string) preg_replace('/\s+/u', ' ', $cmd));
    }

    /** Motif saisi par l'admin, ramené à une ligne sans guillemets. */
    public static function cleanReason(string $reason): string
    {
        $reason = str_replace(['"', "\n", "\r"], ' ', $reason);
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $reason)), 0, 120);
    }

    /** Envoie une commande de modération et interprète la réponse du serveur. */
    private static function moderate(int $id, string $key, string $default, string $reason, string $done): array
    {
        $acc = Db::one('SELECT * FROM accounts WHERE id = ?', [$id]);
        if (!$acc) {
            return ['ok' => false, 'message' => 'Compte introuvable.'];
        }
        $command = self::serverCommand($key, $default, $acc, self::cleanReason($reason));
        if (!Rcon::configured()) {
            return ['ok' => false, 'message' => "RCON n'est pas configuré : tape « $command » dans la console du serveur."];
        }
        try {
            $reply = Rcon::command($command);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => "« $command » n'a pas pu être envoyé : " . $e->getMessage() . '.'];
        }
        if (preg_match('/(no player|not found|introuvable|error|erreur|usage:)/i', $reply)) {
            return ['ok' => false, 'message' => "Le serveur a refusé « $command » : $reply"];
        }
        return [
            'ok' => true,
            'account' => $acc,
            'message' => str_replace('{name}', $acc['username'], $done) . ' Réponse du serveur : ' . ($reply !== '' ? $reply : '(aucune)'),
        ];
    }

    /** Expulse le joueur du serveur (il peut revenir). */
    public static function kick(int $id, string $reason = ''): array
    {
        return self::moderate($id, 'kick_command', 'kick {name} {reason}', $reason, '{name} est expulsé du serveur.');
    }

    /** Bannit le joueur du serveur et désactive son compte sur le site. */
    public static function ban(int $id, string $reason = ''): array
    {
        $r = self::moderate($id, 'ban_command', 'ban {name} {reason}', $reason, '{name} est banni du serveur et son compte du site est désactivé.');
        if ($r['ok']) {
            Db::exec("UPDATE accounts SET status = 'disabled' WHERE id = ?", [$id]);
            Auth::dropSessions($id);
            $r['message'] .= ' ' . self::follow($r['account'], false)['message'];
        }
        return $r;
    }

    /** Lève le bannissement ; le compte du site reste désactivé (bouton « Réactiver »). */
    public static function unban(int $id): array
    {
        return self::moderate($id, 'unban_command', 'pardon {name}', '', "{name} n'est plus banni du serveur.");
    }

    /** Pseudos bannis sur le serveur (minuscules), null si RCON ne répond pas. */
    public static function serverBans(): ?array
    {
        if (!Rcon::configured()) {
            return null;
        }
        try {
            $reply = Rcon::command('banlist players');
        } catch (Throwable $e) {
            return null;
        }
        $names = [];
        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            if (preg_match('/^\s*(\S{1,32})\s+(?:was banned by|a été banni)/iu', $line, $m)) {
                $names[mb_strtolower($m[1])] = true;
            }
        }
        return $names;
    }

    public static function serverWhitelist(): ?array
    {
        if (!Rcon::configured()) {
            return null;
        }
        try {
            $reply = Rcon::command('whitelist list');
        } catch (Throwable $e) {
            return null;
        }
        $names = [];
        if (($pos = strpos($reply, ':')) !== false) {
            foreach (explode(',', substr($reply, $pos + 1)) as $n) {
                $n = trim($n);
                if ($n !== '') {
                    $names[mb_strtolower($n)] = true;
                }
            }
        }
        return $names;
    }
}

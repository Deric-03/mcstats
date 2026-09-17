<?php
/**
 * Journal par joueur (réservé aux admins) :
 *  - actions faites depuis le site (validation, expulsion, bannissement…), table admin_log ;
 *  - périodes de connexion au serveur, table player_sessions, remplie par la synchronisation.
 */
final class Journal
{
    /** Libellés des actions enregistrées. */
    const LABELS = [
        'request' => 'Demande de whitelist',
        'report'  => "Signalement d'usurpation",
        'approve' => 'Demande validée',
        'refuse'  => 'Demande refusée',
        'reset'   => 'Mot de passe réinitialisé',
        'disable' => 'Compte désactivé',
        'enable'  => 'Compte réactivé',
        'promote' => 'Droits admin donnés',
        'demote'  => 'Droits admin retirés',
        'revoke'  => 'Compte révoqué',
        'dismiss' => 'Signalement ignoré',
        'delete'  => 'Compte supprimé',
        'kick'    => 'Expulsé du serveur',
        'ban'     => 'Banni du serveur',
        'unban'   => 'Bannissement levé',
        'console' => 'Commande serveur',
        'console_on'  => 'Accès au terminal donné',
        'console_off' => 'Accès au terminal retiré',
    ];

    /** Actions à afficher en rouge dans le journal. */
    const SEVERE = ['refuse', 'revoke', 'delete', 'ban', 'kick', 'disable', 'report'];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /** Enregistre une action. $actor : pseudo de l'admin, vide si c'est le joueur lui-même. */
    public static function log(string $action, array $account, string $actor = '', string $detail = ''): void
    {
        try {
            Db::exec(
                'INSERT INTO admin_log (account_id, username, username_lc, uuid, actor, action, detail, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) ($account['id'] ?? 0),
                    mb_substr((string) ($account['username'] ?? ''), 0, 40),
                    mb_substr(mb_strtolower((string) ($account['username_lc'] ?? $account['username'] ?? '')), 0, 40),
                    (string) ($account['uuid'] ?? ''),
                    mb_substr($actor, 0, 40),
                    mb_substr($action, 0, 20),
                    mb_substr($detail, 0, 500),
                    time(),
                ]
            );
        } catch (Throwable $e) {
            // le journal ne doit jamais empêcher l'action elle-même
            error_log('[mcstats] journal : ' . $e->getMessage());
        }
    }

    /** Actions concernant un compte (retrouvées même après sa suppression, par le pseudo). */
    public static function forAccount(array $account, int $limit = 200): array
    {
        return Db::all(
            'SELECT * FROM admin_log WHERE account_id = ? OR username_lc = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            [(int) ($account['id'] ?? 0), mb_strtolower((string) ($account['username_lc'] ?? $account['username'] ?? ''))]
        );
    }

    /** Dernières actions, tous comptes confondus. */
    public static function recent(int $limit = 100): array
    {
        return Db::all('SELECT * FROM admin_log ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit));
    }

    /** Périodes de connexion d'un joueur, la plus récente d'abord. */
    public static function sessions(string $uuid, int $limit = 100): array
    {
        if ($uuid === '') {
            return [];
        }
        return Db::all(
            'SELECT * FROM player_sessions WHERE uuid = ? ORDER BY started_at DESC LIMIT ' . max(1, $limit),
            [$uuid]
        );
    }

    /** Temps passé en ligne depuis $since (secondes), sessions en cours comprises. */
    public static function timeSince(array $sessions, int $since): int
    {
        $total = 0;
        $now = time();
        foreach ($sessions as $s) {
            $end = (int) $s['ended_at'] ?: $now;
            $total += max(0, $end - max((int) $s['started_at'], $since));
        }
        return $total;
    }

    /** Début d'une session (le joueur vient de se connecter). */
    public static function openSession(string $uuid, int $now): void
    {
        $open = Db::value('SELECT started_at FROM player_sessions WHERE uuid = ? AND ended_at = 0', [$uuid]);
        if ($open === null) {
            Db::exec('INSERT INTO player_sessions (uuid, started_at, ended_at) VALUES (?, ?, 0)', [$uuid, $now]);
        }
    }

    /** Fin d'une session (déconnexion, ou serveur arrêté). */
    public static function closeSession(string $uuid, int $now): void
    {
        Db::exec('UPDATE player_sessions SET ended_at = ? WHERE uuid = ? AND ended_at = 0', [$now, $uuid]);
    }

    /** Ménage : sessions de plus de 400 jours. */
    public static function cleanup(int $now): void
    {
        Db::exec('DELETE FROM player_sessions WHERE ended_at > 0 AND ended_at < ?', [$now - 400 * 86400]);
    }
}

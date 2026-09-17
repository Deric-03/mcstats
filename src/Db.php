<?php
/**
 * Accès base de données (PDO) : MySQL / MariaDB ou SQLite.
 */
final class Db
{
    const SCHEMA_VERSION = 9;

    /** Colonnes ajoutées après la première version (ajoutées automatiquement si absentes). */
    const EXTRA_COLUMNS = [
        'online'       => 'TINYINT NOT NULL DEFAULT 0',
        'online_since' => 'BIGINT NOT NULL DEFAULT 0',
        'pos_dim'      => "VARCHAR(64) NOT NULL DEFAULT ''",
        'pos_x'        => 'INT NOT NULL DEFAULT 0',
        'pos_y'        => 'INT NOT NULL DEFAULT 0',
        'pos_z'        => 'INT NOT NULL DEFAULT 0',
        'skin'         => "VARCHAR(64) NOT NULL DEFAULT ''",
        'skin_checked' => 'BIGINT NOT NULL DEFAULT 0',
    ];

    private static $pdo;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $c = App::cfg('db');
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (self::driver() === 'sqlite') {
            $path = $c['sqlite_path'] ?: App::dataDir() . '/mcstats.sqlite';
            self::$pdo = new PDO('sqlite:' . $path, null, null, $opts);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['name']);
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
        }
        self::ensureSchema();
        return self::$pdo;
    }

    public static function driver(): string
    {
        return App::cfg('db.driver') === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function meta(string $key, $default = null)
    {
        $v = self::value('SELECT v FROM meta WHERE k = ?', [$key]);
        return $v === null ? $default : $v;
    }

    public static function setMeta(string $key, $value): void
    {
        self::exec('REPLACE INTO meta (k, v) VALUES (?, ?)', [$key, (string) $value]);
    }

    /** Crée les tables manquantes et ajoute les colonnes de catégories manquantes. */
    private static function ensureSchema(): void
    {
        $pdo = self::$pdo;
        try {
            $v = $pdo->query("SELECT v FROM meta WHERE k = 'schema'")->fetchColumn();
            if ((int) $v === self::SCHEMA_VERSION && $pdo->query("SELECT v FROM meta WHERE k = 'columns'")->fetchColumn() === self::columnsSignature()) {
                return;
            }
        } catch (PDOException $e) {
            // tables absentes : on les crée
        }

        $suffix = self::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v MEDIUMTEXT NULL
        )' . $suffix);

        $pdo->exec('CREATE TABLE IF NOT EXISTS players (
            uuid CHAR(36) NOT NULL PRIMARY KEY,
            name VARCHAR(32) NOT NULL DEFAULT \'\',
            hidden TINYINT NOT NULL DEFAULT 0,
            first_seen BIGINT NOT NULL DEFAULT 0,
            last_seen BIGINT NOT NULL DEFAULT 0,
            gamemode INT NOT NULL DEFAULT 0,
            online TINYINT NOT NULL DEFAULT 0,
            online_since BIGINT NOT NULL DEFAULT 0,
            stats_json MEDIUMTEXT NULL,
            adv_json MEDIUMTEXT NULL,
            nbt_json MEDIUMTEXT NULL,
            mtime_stats BIGINT NOT NULL DEFAULT 0,
            mtime_adv BIGINT NOT NULL DEFAULT 0,
            mtime_dat BIGINT NOT NULL DEFAULT 0,
            name_checked BIGINT NOT NULL DEFAULT 0,
            updated_at BIGINT NOT NULL DEFAULT 0
        )' . $suffix);

        $pdo->exec('CREATE TABLE IF NOT EXISTS player_history (
            uuid CHAR(36) NOT NULL,
            day CHAR(10) NOT NULL,
            play_time BIGINT NOT NULL DEFAULT 0,
            score DOUBLE NOT NULL DEFAULT 0,
            mob_kills BIGINT NOT NULL DEFAULT 0,
            deaths BIGINT NOT NULL DEFAULT 0,
            blocks_mined BIGINT NOT NULL DEFAULT 0,
            distance BIGINT NOT NULL DEFAULT 0,
            advancements INT NOT NULL DEFAULT 0,
            PRIMARY KEY (uuid, day)
        )' . $suffix);

        // Comptes du site : une demande de whitelist crée un compte en attente, activé à la validation
        $id = self::driver() === 'sqlite' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
            $id,
            username VARCHAR(40) NOT NULL,
            username_lc VARCHAR(40) NOT NULL UNIQUE,
            edition VARCHAR(10) NOT NULL DEFAULT 'java',
            uuid VARCHAR(36) NOT NULL DEFAULT '',
            verified TINYINT NOT NULL DEFAULT 1,
            password_hash VARCHAR(255) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            is_admin TINYINT NOT NULL DEFAULT 0,
            is_owner TINYINT NOT NULL DEFAULT 0,
            can_console TINYINT NOT NULL DEFAULT 0,
            must_change_password TINYINT NOT NULL DEFAULT 0,
            message VARCHAR(500) NOT NULL DEFAULT '',
            admin_note VARCHAR(500) NOT NULL DEFAULT '',
            created_at BIGINT NOT NULL DEFAULT 0,
            decided_at BIGINT NOT NULL DEFAULT 0,
            last_login BIGINT NOT NULL DEFAULT 0
        )" . $suffix);

        // compte protégé : l'admin principal, sur lequel les autres admins n'ont aucun droit
        self::addColumns($pdo, 'accounts', [
            'is_owner'    => 'TINYINT NOT NULL DEFAULT 0',
            'can_console' => 'TINYINT NOT NULL DEFAULT 0',   // terminal RCON, accordé par l'admin principal
        ]);

        $pdo->exec('CREATE TABLE IF NOT EXISTS auth_sessions (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            account_id INT NOT NULL,
            created_at BIGINT NOT NULL DEFAULT 0,
            expires_at BIGINT NOT NULL DEFAULT 0
        )' . $suffix);

        $pdo->exec('CREATE TABLE IF NOT EXISTS auth_attempts (
            k VARCHAR(120) NOT NULL,
            at BIGINT NOT NULL DEFAULT 0
        )' . $suffix);

        // Signalements d'usurpation : un joueur conteste un compte créé avec son pseudo
        $pdo->exec("CREATE TABLE IF NOT EXISTS identity_reports (
            $id,
            account_id INT NOT NULL,
            discord VARCHAR(40) NOT NULL DEFAULT '',
            message VARCHAR(500) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            created_at BIGINT NOT NULL DEFAULT 0,
            decided_at BIGINT NOT NULL DEFAULT 0
        )" . $suffix);

        // Journal : actions faites depuis le site, et périodes de connexion des joueurs
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_log (
            $id,
            account_id INT NOT NULL DEFAULT 0,
            username VARCHAR(40) NOT NULL DEFAULT '',
            username_lc VARCHAR(40) NOT NULL DEFAULT '',
            uuid VARCHAR(36) NOT NULL DEFAULT '',
            actor VARCHAR(40) NOT NULL DEFAULT '',
            action VARCHAR(20) NOT NULL DEFAULT '',
            detail VARCHAR(500) NOT NULL DEFAULT '',
            created_at BIGINT NOT NULL DEFAULT 0
        )" . $suffix);

        $pdo->exec('CREATE TABLE IF NOT EXISTS player_sessions (
            uuid CHAR(36) NOT NULL,
            started_at BIGINT NOT NULL DEFAULT 0,
            ended_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (uuid, started_at)
        )' . $suffix);

        // Événements lus dans le journal du serveur (morts, connexions, chat…)
        $pdo->exec("CREATE TABLE IF NOT EXISTS server_events (
            $id,
            sig CHAR(32) NOT NULL UNIQUE,
            at BIGINT NOT NULL DEFAULT 0,
            kind VARCHAR(16) NOT NULL DEFAULT '',
            player VARCHAR(40) NOT NULL DEFAULT '',
            player_lc VARCHAR(40) NOT NULL DEFAULT '',
            message VARCHAR(500) NOT NULL DEFAULT '',
            cause VARCHAR(32) NOT NULL DEFAULT '',
            killer VARCHAR(60) NOT NULL DEFAULT '',
            dim VARCHAR(48) NOT NULL DEFAULT '',
            x INT NOT NULL DEFAULT 0,
            y INT NOT NULL DEFAULT 0,
            z INT NOT NULL DEFAULT 0
        )" . $suffix);
        // colonnes ajoutées après coup (bases créées par une version précédente)
        self::addColumns($pdo, 'server_events', [
            'cause'  => "VARCHAR(32) NOT NULL DEFAULT ''",
            'killer' => "VARCHAR(60) NOT NULL DEFAULT ''",
            'dim'    => "VARCHAR(48) NOT NULL DEFAULT ''",
            'x'      => 'INT NOT NULL DEFAULT 0',
            'y'      => 'INT NOT NULL DEFAULT 0',
            'z'      => 'INT NOT NULL DEFAULT 0',
        ]);
        foreach (['CREATE INDEX IF NOT EXISTS idx_events_player ON server_events (player_lc, at)',
                  'CREATE INDEX IF NOT EXISTS idx_events_at ON server_events (at)',
                  'CREATE INDEX IF NOT EXISTS idx_log_account ON admin_log (account_id)'] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // MySQL < 8 ne connaît pas IF NOT EXISTS sur les index : l'index existe déjà
            }
        }

        // Données de plugins par joueur (sac à dos, homes), en JSON
        $pdo->exec('CREATE TABLE IF NOT EXISTS plugin_data (
            plugin VARCHAR(20) NOT NULL,
            uuid CHAR(36) NOT NULL,
            data MEDIUMTEXT NULL,
            updated_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (plugin, uuid)
        )' . $suffix);

        // Colonnes de catégories (une par statistique classée)
        $existing = self::columnsOf($pdo, 'players');
        foreach (Stats::CATEGORIES as $key => $cat) {
            if (!isset($existing[$key])) {
                $type = ($cat['type'] ?? 'int') === 'float' ? 'DOUBLE' : 'BIGINT';
                $pdo->exec("ALTER TABLE players ADD COLUMN $key $type NOT NULL DEFAULT 0");
            }
        }
        foreach (self::EXTRA_COLUMNS as $key => $def) {
            if (!isset($existing[$key])) {
                $pdo->exec("ALTER TABLE players ADD COLUMN $key $def");
            }
        }

        $pdo->exec("REPLACE INTO meta (k, v) VALUES ('schema', '" . self::SCHEMA_VERSION . "')");
        $pdo->exec("REPLACE INTO meta (k, v) VALUES ('columns', '" . self::columnsSignature() . "')");
    }

    /** Colonnes existantes d'une table : nom en minuscules => true. */
    private static function columnsOf(PDO $pdo, string $table): array
    {
        $out = [];
        if (self::driver() === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
                $out[strtolower($col['name'])] = true;
            }
        } else {
            foreach ($pdo->query("SHOW COLUMNS FROM $table")->fetchAll() as $col) {
                $out[strtolower($col['Field'])] = true;
            }
        }
        return $out;
    }

    /** Ajoute les colonnes manquantes d'une table. */
    private static function addColumns(PDO $pdo, string $table, array $columns): void
    {
        $existing = self::columnsOf($pdo, $table);
        foreach ($columns as $name => $def) {
            if (!isset($existing[$name])) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $name $def");
            }
        }
    }

    private static function columnsSignature(): string
    {
        return md5(implode(',', array_merge(array_keys(Stats::CATEGORIES), array_keys(self::EXTRA_COLUMNS))));
    }
}

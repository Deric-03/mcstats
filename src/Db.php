<?php
/**
 * Accès base de données (PDO) : MySQL / MariaDB ou SQLite.
 */
final class Db
{
    const SCHEMA_VERSION = 2;

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

        // Colonnes de catégories (une par statistique classée)
        $existing = [];
        if (self::driver() === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(players)')->fetchAll() as $col) {
                $existing[strtolower($col['name'])] = true;
            }
        } else {
            foreach ($pdo->query('SHOW COLUMNS FROM players')->fetchAll() as $col) {
                $existing[strtolower($col['Field'])] = true;
            }
        }
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

    private static function columnsSignature(): string
    {
        return md5(implode(',', array_merge(array_keys(Stats::CATEGORIES), array_keys(self::EXTRA_COLUMNS))));
    }
}

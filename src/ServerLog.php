<?php
/**
 * Lecture du journal du serveur Minecraft (logs/latest.log).
 *
 * À chaque synchronisation, seules les nouvelles lignes sont lues (un curseur est gardé en base) :
 * morts, connexions, chat, commandes et succès sont rangés dans la table server_events.
 * Le fichier est relu depuis le début quand il change (rotation de minuit).
 */
final class ServerLog
{
    /** Types d'événements reconnus : clé => [libellé, icône Minecraft]. */
    const KINDS = [
        'death'       => ['Mort', 'bone'],
        'join'        => ['Connexion', 'oak_door'],
        'leave'       => ['Déconnexion', 'iron_door'],
        'chat'        => ['Chat', 'writable_book'],
        'command'     => ['Commande', 'command_block'],
        'advancement' => ['Succès', 'knowledge_book'],
    ];

    /** Quantité maximale lue en une passe (le reste sera lu à la synchronisation suivante). */
    const MAX_BYTES = 4194304;

    public static function path(): string
    {
        return trim((string) App::cfg('server_log.path', ''));
    }

    public static function enabled(): bool
    {
        return self::path() !== '';
    }

    public static function label(string $kind): string
    {
        return self::KINDS[$kind][0] ?? $kind;
    }

    public static function icon(string $kind): string
    {
        return self::KINDS[$kind][1] ?? 'paper';
    }

    /** État de la dernière lecture (espace admin). */
    public static function status(): array
    {
        return json_decode((string) Db::meta('serverlog_info', ''), true) ?: [];
    }

    /** Événements d'un joueur, du plus récent au plus ancien. */
    public static function forPlayer(string $name, int $limit = 200): array
    {
        if ($name === '') {
            return [];
        }
        return Db::all(
            'SELECT * FROM server_events WHERE player_lc = ? ORDER BY at DESC, id DESC LIMIT ' . max(1, $limit),
            [mb_strtolower($name)]
        );
    }

    /** Derniers événements, tous joueurs confondus. */
    public static function recent(int $limit = 100): array
    {
        return Db::all('SELECT * FROM server_events ORDER BY at DESC, id DESC LIMIT ' . max(1, $limit));
    }

    /** Appelé par la synchronisation : lit les nouvelles lignes et range les événements. */
    public static function sync(callable $log): void
    {
        $file = self::path();
        if ($file === '') {
            if (Db::meta('serverlog_info') !== null) {
                Db::setMeta('serverlog_info', '');
                Db::setMeta('serverlog_pos', '0');
            }
            return;
        }
        try {
            $added = self::read($file);
            Db::setMeta('serverlog_info', json_encode(['error' => '', 'at' => time(), 'added' => $added], JSON_UNESCAPED_UNICODE));
            if ($added) {
                $log(sprintf('Journal du serveur : %d événement(s) enregistré(s).', $added));
            }
        } catch (Throwable $e) {
            Db::setMeta('serverlog_info', json_encode(['error' => $e->getMessage(), 'at' => time(), 'added' => 0], JSON_UNESCAPED_UNICODE));
            $log('ATTENTION : journal du serveur : ' . $e->getMessage());
        }
    }

    private static function read(string $file): int
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("fichier introuvable ou illisible : $file");
        }
        $size = (int) filesize($file);
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            throw new RuntimeException("ouverture impossible : $file");
        }
        try {
            $key = md5((string) fread($fh, 256));
            $pos = (int) Db::meta('serverlog_pos', 0);
            if ($pos > $size || Db::meta('serverlog_key', '') !== $key) {
                $pos = 0;   // nouveau fichier (rotation de minuit) ou première lecture
            }
            if ($pos >= $size) {
                Db::setMeta('serverlog_key', $key);
                return 0;
            }
            fseek($fh, $pos);
            $data = (string) fread($fh, min(self::MAX_BYTES, $size - $pos));
        } finally {
            fclose($fh);
        }
        $cut = strrpos($data, "\n");
        if ($cut === false) {
            return 0;   // ligne encore incomplète : on attendra la prochaine fois
        }
        $lines = explode("\n", substr($data, 0, $cut));
        $events = self::parse($lines, (int) filemtime($file));
        $added = self::store($events);
        Db::setMeta('serverlog_pos', (string) ($pos + $cut + 1));
        Db::setMeta('serverlog_key', $key);
        self::cleanup();
        return $added;
    }

    /** Transforme les lignes du journal en événements. */
    public static function parse(array $lines, int $fileTime): array
    {
        $known = [];
        foreach (Db::all('SELECT name FROM players') as $p) {
            if ($p['name'] !== '') {
                $known[mb_strtolower($p['name'])] = $p['name'];
            }
        }
        $day = date('Y-m-d', $fileTime ?: time());
        $out = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            // [20:13:45] [Server thread/INFO]: message   ou   [20:13:45 INFO]: message
            if (preg_match('/^\[(\d{2}:\d{2}:\d{2})\]\s*\[([^\]]*)\]:\s?(.*)$/u', $line, $m)) {
                [$time, $thread, $body] = [$m[1], $m[2], $m[3]];
            } elseif (preg_match('/^\[(\d{2}:\d{2}:\d{2})\s+([A-Z]+)\]:\s?(.*)$/u', $line, $m)) {
                [$time, $thread, $body] = [$m[1], $m[2], $m[3]];
            } else {
                continue;
            }
            if (stripos($thread, 'INFO') === false) {
                continue;
            }
            $at = (int) strtotime("$day $time");
            if ($at > time() + 120) {
                $at -= 86400;   // lignes d'hier lues juste après la rotation
            }
            $body = trim((string) preg_replace('/^\[Not Secure\]\s*/', '', $body));
            $event = self::classify($body, $known);
            if ($event) {
                $out[] = ['at' => $at] + $event;
                $known[mb_strtolower($event['player'])] = $event['player'];
            }
        }
        return $out;
    }

    /** Reconnaît une ligne : chat, connexion, commande, succès ou message de mort. */
    private static function classify(string $body, array $known): ?array
    {
        $make = function (string $kind, string $player, string $message) {
            return ['kind' => $kind, 'player' => mb_substr($player, 0, 40), 'message' => mb_substr($message, 0, 500)];
        };
        if (preg_match('/^<([^>]{1,40})>\s?(.*)$/u', $body, $m)) {
            return App::cfg('server_log.chat', true) ? $make('chat', $m[1], $m[2]) : null;
        }
        if (preg_match('/^(\S{1,40}) joined the game$/u', $body, $m)) {
            return $make('join', $m[1], $body);
        }
        if (preg_match('/^(\S{1,40}) left the game$/u', $body, $m)) {
            return $make('leave', $m[1], $body);
        }
        if (preg_match('/^(\S{1,40}) issued server command: (.+)$/u', $body, $m)) {
            return $make('command', $m[1], $m[2]);
        }
        if (preg_match('/^(\S{1,40}) has (?:made the advancement|completed the challenge|reached the goal) \[(.+)\]$/u', $body, $m)) {
            return $make('advancement', $m[1], $m[2]);
        }
        // Message de mort : commence par un joueur connu, sans être une ligne technique
        if (preg_match('/^(\S{1,40})\s+\S/u', $body, $m) && isset($known[mb_strtolower($m[1])])
            && mb_strlen($body) <= 200
            && !preg_match('/(lost connection|logged in with entity id|moved too quickly|moved wrongly|\[\/)/i', $body)) {
            return $make('death', $m[1], $body);
        }
        return null;
    }

    /** Enregistre les événements (les doublons, en cas de relecture, sont ignorés). */
    private static function store(array $events): int
    {
        if (!$events) {
            return 0;
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('INSERT INTO server_events (sig, at, kind, player, player_lc, message) VALUES (?, ?, ?, ?, ?, ?)');
        $added = 0;
        $pdo->beginTransaction();
        foreach ($events as $e) {
            $sig = md5($e['at'] . '|' . $e['kind'] . '|' . mb_strtolower($e['player']) . '|' . $e['message']);
            try {
                $stmt->execute([$sig, $e['at'], $e['kind'], $e['player'], mb_strtolower($e['player']), $e['message']]);
                $added++;
            } catch (PDOException $x) {
                // déjà enregistré
            }
        }
        $pdo->commit();
        return $added;
    }

    private static function cleanup(): void
    {
        $days = max(1, (int) App::cfg('server_log.keep_days', 90));
        Db::exec('DELETE FROM server_events WHERE at < ?', [time() - $days * 86400]);
    }
}

<?php
/**
 * Client RCON minimal (protocole Source RCON utilisé par Minecraft) pour envoyer des commandes au serveur.
 * À activer dans server.properties : enable-rcon=true, rcon.port, rcon.password.
 */
final class Rcon
{
    const TYPE_AUTH = 3;
    const TYPE_COMMAND = 2;

    public static function configured(): bool
    {
        return (string) App::cfg('server.rcon_password', '') !== '';
    }

    /**
     * Envoie une commande et renvoie la réponse du serveur (codes couleur retirés).
     * @throws RuntimeException connexion, mot de passe ou réponse invalide
     */
    public static function command(string $command): string
    {
        $host = (string) App::cfg('server.rcon_host', App::cfg('server.host', '127.0.0.1'));
        $port = (int) App::cfg('server.rcon_port', 25575);
        $timeout = max(1, (int) App::cfg('server.timeout', 2));

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new RuntimeException("connexion RCON impossible sur $host:$port" . ($errstr ? " ($errstr)" : ''));
        }
        stream_set_timeout($fp, $timeout);
        try {
            self::send($fp, 1, self::TYPE_AUTH, (string) App::cfg('server.rcon_password', ''));
            if (self::read($fp)['id'] === -1) {
                throw new RuntimeException('mot de passe RCON refusé par le serveur');
            }
            self::send($fp, 2, self::TYPE_COMMAND, $command);
            return trim((string) preg_replace('/§./u', '', self::read($fp)['body']));
        } finally {
            fclose($fp);
        }
    }

    private static function send($fp, int $id, int $type, string $body): void
    {
        $packet = pack('VV', $id, $type) . $body . "\x00\x00";
        fwrite($fp, pack('V', strlen($packet)) . $packet);
    }

    private static function read($fp): array
    {
        $length = unpack('V', self::readBytes($fp, 4))[1];
        if ($length < 10 || $length > 1048576) {
            throw new RuntimeException('réponse RCON invalide');
        }
        $data = self::readBytes($fp, $length);
        $head = unpack('Vid/Vtype', substr($data, 0, 8));
        $id = $head['id'] > 0x7FFFFFFF ? $head['id'] - 0x100000000 : $head['id'];
        return ['id' => $id, 'type' => $head['type'], 'body' => substr($data, 8, -2)];
    }

    private static function readBytes($fp, int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = fread($fp, $n - strlen($out));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'le serveur ne répond pas (RCON)' : 'connexion RCON interrompue');
            }
            $out .= $chunk;
        }
        return $out;
    }
}

<?php
/**
 * Statut du serveur Minecraft via le protocole "Server List Ping" (1.7+).
 */
final class ServerStatus
{
    const COLORS = [
        'black' => '#000000', 'dark_blue' => '#0000AA', 'dark_green' => '#00AA00', 'dark_aqua' => '#00AAAA',
        'dark_red' => '#AA0000', 'dark_purple' => '#AA00AA', 'gold' => '#FFAA00', 'gray' => '#AAAAAA',
        'dark_gray' => '#555555', 'blue' => '#5555FF', 'green' => '#55FF55', 'aqua' => '#55FFFF',
        'red' => '#FF5555', 'light_purple' => '#FF55FF', 'yellow' => '#FFFF55', 'white' => '#FFFFFF',
    ];
    const LEGACY = '0123456789abcdef';

    /** Statut mis en cache quelques secondes dans data/status.json. */
    public static function get(): array
    {
        if (!App::cfg('server.enabled', true)) {
            return ['enabled' => false];
        }
        $file = App::dataDir() . '/status.json';
        $ttl = max(5, (int) App::cfg('server.cache_seconds', 30));
        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && time() - (int) ($cached['checked_at'] ?? 0) < $ttl) {
            return $cached;
        }
        $s = self::query((string) App::cfg('server.host', '127.0.0.1'), (int) App::cfg('server.port', 25565), (float) App::cfg('server.timeout', 2));
        $s['enabled'] = true;
        $s['address'] = (string) App::cfg('server.display_address', '');
        @file_put_contents($file, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $s;
    }

    public static function query(string $host, int $port, float $timeout): array
    {
        $out = ['online' => false, 'checked_at' => time()];
        $connectHost = $host;
        $connectPort = $port;
        if ($port === 25565 && !filter_var($host, FILTER_VALIDATE_IP) && function_exists('dns_get_record')) {
            $srv = @dns_get_record('_minecraft._tcp.' . $host, DNS_SRV);
            if (!empty($srv[0]['target'])) {
                $connectHost = $srv[0]['target'];
                $connectPort = (int) $srv[0]['port'];
            }
        }

        $fp = @fsockopen($connectHost, $connectPort, $errno, $errstr, $timeout);
        if (!$fp) {
            $out['error'] = $errstr ?: 'Connexion impossible';
            return $out;
        }
        stream_set_timeout($fp, max(1, (int) ceil($timeout)));
        try {
            $t0 = microtime(true);
            $handshake = "\x00" . self::varint(-1) . self::varint(strlen($host)) . $host . pack('n', $port) . self::varint(1);
            fwrite($fp, self::varint(strlen($handshake)) . $handshake);
            fwrite($fp, "\x01\x00");

            self::readVarint($fp);            // longueur du paquet
            if (self::readVarint($fp) !== 0) { // identifiant du paquet
                throw new RuntimeException('Réponse inattendue');
            }
            $len = self::readVarint($fp);
            $json = '';
            while (strlen($json) < $len) {
                $chunk = fread($fp, $len - strlen($json));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Réponse tronquée');
                }
                $json .= $chunk;
            }
            $latency = (microtime(true) - $t0) * 1000;

            // Ping pour une latence plus précise (facultatif)
            try {
                $t1 = microtime(true);
                fwrite($fp, "\x09\x01" . pack('J', 42));
                self::readVarint($fp);
                self::readVarint($fp);
                if (strlen((string) fread($fp, 8)) === 8) {
                    $latency = (microtime(true) - $t1) * 1000;
                }
            } catch (Throwable $e) {
                // certains proxys ne répondent pas au ping
            }

            $d = json_decode($json, true);
            if (!is_array($d)) {
                throw new RuntimeException('JSON de statut invalide');
            }
            $sample = [];
            foreach ((array) ($d['players']['sample'] ?? []) as $pl) {
                $id = strtolower((string) ($pl['id'] ?? ''));
                if ($id !== '' && $id !== '00000000-0000-0000-0000-000000000000') {
                    $sample[] = ['name' => (string) ($pl['name'] ?? ''), 'id' => $id];
                }
            }
            $out = [
                'online'     => true,
                'checked_at' => time(),
                'version'    => preg_replace('/§./u', '', (string) ($d['version']['name'] ?? '')),
                'protocol'   => (int) ($d['version']['protocol'] ?? 0),
                'players'    => [
                    'online' => (int) ($d['players']['online'] ?? 0),
                    'max'    => (int) ($d['players']['max'] ?? 0),
                    'sample' => $sample,
                ],
                'motd_html'  => self::motdHtml($d['description'] ?? ''),
                'favicon'    => (isset($d['favicon']) && strpos($d['favicon'], 'data:image/png;base64,') === 0) ? $d['favicon'] : null,
                'latency'    => (int) round($latency),
            ];
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        } finally {
            fclose($fp);
        }
        return $out;
    }

    private static function varint(int $v): string
    {
        $v &= 0xFFFFFFFF;
        $out = '';
        do {
            $b = $v & 0x7F;
            $v >>= 7;
            if ($v) {
                $b |= 0x80;
            }
            $out .= chr($b);
        } while ($v);
        return $out;
    }

    private static function readVarint($fp): int
    {
        $v = 0;
        for ($i = 0; $i < 5; $i++) {
            $c = fgetc($fp);
            if ($c === false) {
                throw new RuntimeException('Pas de réponse du serveur');
            }
            $b = ord($c);
            $v |= ($b & 0x7F) << (7 * $i);
            if (!($b & 0x80)) {
                return $v;
            }
        }
        throw new RuntimeException('VarInt invalide');
    }

    /** MOTD (texte "§" ou composant JSON) -> HTML sûr. */
    public static function motdHtml($c, array $style = []): string
    {
        if (is_string($c)) {
            return self::legacyHtml($c, $style);
        }
        if (!is_array($c)) {
            return '';
        }
        if ($c !== [] && array_keys($c) === range(0, count($c) - 1)) {
            $html = '';
            foreach ($c as $part) {
                $html .= self::motdHtml($part, $style);
            }
            return $html;
        }
        foreach (['color', 'bold', 'italic', 'underlined', 'strikethrough'] as $k) {
            if (isset($c[$k])) {
                $style[$k] = $c[$k];
            }
        }
        $html = '';
        $text = $c['text'] ?? ($c[''] ?? '');
        if ($text !== '') {
            $html .= self::legacyHtml((string) $text, $style);
        }
        foreach ((array) ($c['extra'] ?? []) as $e) {
            $html .= self::motdHtml($e, $style);
        }
        return $html;
    }

    private static function legacyHtml(string $s, array $style): string
    {
        $html = '';
        $parts = preg_split('/(§[0-9a-fk-or])/iu', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $part) {
            if (preg_match('/^§([0-9a-fk-or])$/iu', $part, $m)) {
                $code = strtolower($m[1]);
                $pos = strpos(self::LEGACY, $code);
                if ($pos !== false) {
                    $style = ['color' => array_keys(self::COLORS)[$pos]];
                } elseif ($code === 'l') {
                    $style['bold'] = true;
                } elseif ($code === 'o') {
                    $style['italic'] = true;
                } elseif ($code === 'n') {
                    $style['underlined'] = true;
                } elseif ($code === 'm') {
                    $style['strikethrough'] = true;
                } elseif ($code === 'r') {
                    $style = [];
                }
                continue;
            }
            if ($part === '') {
                continue;
            }
            $css = [];
            $color = $style['color'] ?? null;
            if ($color && isset(self::COLORS[$color])) {
                $css[] = 'color:' . self::COLORS[$color];
            } elseif ($color && preg_match('/^#[0-9a-f]{6}$/i', $color)) {
                $css[] = 'color:' . $color;
            }
            if (!empty($style['bold'])) {
                $css[] = 'font-weight:700';
            }
            if (!empty($style['italic'])) {
                $css[] = 'font-style:italic';
            }
            $deco = [];
            if (!empty($style['underlined'])) {
                $deco[] = 'underline';
            }
            if (!empty($style['strikethrough'])) {
                $deco[] = 'line-through';
            }
            if ($deco) {
                $css[] = 'text-decoration:' . implode(' ', $deco);
            }
            $text = nl2br(htmlspecialchars($part, ENT_QUOTES, 'UTF-8'));
            $html .= $css ? '<span style="' . implode(';', $css) . '">' . $text . '</span>' : $text;
        }
        return $html;
    }
}

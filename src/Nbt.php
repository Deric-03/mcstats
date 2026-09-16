<?php
/**
 * Lecteur NBT minimaliste (format binaire de Minecraft).
 * Transforme un fichier .dat (gzip / zlib / brut) en tableau PHP.
 */
final class Nbt
{
    private $d;
    private $p = 0;
    private $len;

    private function __construct(string $data)
    {
        $this->d = $data;
        $this->len = strlen($data);
    }

    /** Lit un fichier NBT et renvoie le compound racine, ou null en cas d'erreur. */
    public static function readFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        return self::readString($raw);
    }

    public static function readString(string $raw): ?array
    {
        if (substr($raw, 0, 2) === "\x1f\x8b") {
            $raw = @gzdecode($raw);
        } elseif (substr($raw, 0, 1) === "\x78") {
            $raw = @gzuncompress($raw);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $n = new self($raw);
            if ($n->u8() !== 10) {
                return null;
            }
            $n->str();
            return $n->payload(10);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function take(int $n): string
    {
        if ($n < 0 || $this->p + $n > $this->len) {
            throw new RuntimeException('NBT: fin de fichier inattendue');
        }
        $s = substr($this->d, $this->p, $n);
        $this->p += $n;
        return $s;
    }

    private function u8(): int
    {
        return ord($this->take(1));
    }

    private function i32(): int
    {
        $v = unpack('N', $this->take(4))[1];
        return $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
    }

    private function str(): string
    {
        $n = unpack('n', $this->take(2))[1];
        return $n ? $this->take($n) : '';
    }

    private function payload(int $t)
    {
        switch ($t) {
            case 1: // byte
                $v = $this->u8();
                return $v > 127 ? $v - 256 : $v;
            case 2: // short
                $v = unpack('n', $this->take(2))[1];
                return $v > 32767 ? $v - 65536 : $v;
            case 3: // int
                return $this->i32();
            case 4: // long
                return unpack('J', $this->take(8))[1];
            case 5: // float
                return round(unpack('G', $this->take(4))[1], 4);
            case 6: // double
                return unpack('E', $this->take(8))[1];
            case 7: // byte array
                $n = $this->i32();
                return $n > 0 ? array_map(function ($b) {
                    return $b > 127 ? $b - 256 : $b;
                }, array_values(unpack('C*', $this->take($n)))) : [];
            case 8: // string
                return $this->str();
            case 9: // list
                $type = $this->u8();
                $n = $this->i32();
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->payload($type);
                }
                return $out;
            case 10: // compound
                $out = [];
                while (($type = $this->u8()) !== 0) {
                    $name = $this->str();
                    $out[$name] = $this->payload($type);
                }
                return $out;
            case 11: // int array
                $n = $this->i32();
                if ($n <= 0) {
                    return [];
                }
                return array_map(function ($v) {
                    return $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
                }, array_values(unpack('N*', $this->take($n * 4))));
            case 12: // long array
                $n = $this->i32();
                return $n > 0 ? array_values(unpack('J*', $this->take($n * 8))) : [];
        }
        throw new RuntimeException('NBT: type inconnu ' . $t);
    }
}

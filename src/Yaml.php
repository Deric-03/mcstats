<?php
/**
 * Lecteur YAML minimaliste pour les fichiers de données des plugins (homes…).
 * Gère les dictionnaires imbriqués et les valeurs simples ; les listes et les textes
 * sur plusieurs lignes sont ignorés (inutiles au site).
 */
final class Yaml
{
    public static function parseFile(string $file): ?array
    {
        $raw = @file_get_contents($file);
        return $raw === false ? null : self::parse($raw);
    }

    public static function parse(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) as $raw) {
            $s = trim($raw);
            if ($s === '' || $s[0] === '#' || $s === '---' || $s === '...') {
                continue;
            }
            $lines[] = [strlen($raw) - strlen(ltrim($raw, " \t")), $s];
        }
        $i = 0;
        return self::block($lines, $i);
    }

    /** Lit les lignes d'un même niveau d'indentation (et leurs enfants) à partir de $i. */
    private static function block(array $lines, int &$i): array
    {
        $out = [];
        if (!isset($lines[$i])) {
            return $out;
        }
        $base = $lines[$i][0];
        while (isset($lines[$i]) && $lines[$i][0] >= $base) {
            [$indent, $s] = $lines[$i];
            $i++;
            if ($indent > $base || $s[0] === '-'
                || !preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\'"#][^#]*?)\s*:(?:\s+(.*))?$/', $s, $m)) {
                // liste, ligne orpheline ou syntaxe non gérée : ignorée avec ses enfants
                self::skipChildren($lines, $i, $indent);
                continue;
            }
            $key = (string) self::scalar($m[1]);
            $value = trim($m[2] ?? '');
            if ($value === '' || $value[0] === '&') {
                $out[$key] = isset($lines[$i]) && $lines[$i][0] > $indent ? self::block($lines, $i) : null;
            } else {
                $out[$key] = self::scalar($value);
                self::skipChildren($lines, $i, $indent);
            }
        }
        return $out;
    }

    private static function skipChildren(array $lines, int &$i, int $indent): void
    {
        while (isset($lines[$i]) && $lines[$i][0] > $indent) {
            $i++;
        }
    }

    private static function scalar(string $v)
    {
        if ($v === '') {
            return '';
        }
        if ($v[0] === '"') {
            $j = json_decode(preg_replace('/"\s*(#.*)?$/', '"', $v));
            return is_string($j) ? $j : trim($v, '"');
        }
        if ($v[0] === "'") {
            return str_replace("''", "'", (string) preg_replace("/^'|'\s*(#.*)?$/", '', $v));
        }
        $v = rtrim((string) preg_replace('/\s+#.*$/', '', $v));
        if (preg_match('/^[-+]?\d+$/', $v)) {
            return (int) $v;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $lc = strtolower($v);
        if (in_array($lc, ['true', 'false'], true)) {
            return $lc === 'true';
        }
        if (in_array($lc, ['null', '~'], true)) {
            return null;
        }
        if ($v === '[]' || $v === '{}') {
            return [];
        }
        return $v;
    }
}

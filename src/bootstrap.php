<?php
/**
 * Point d'entrée commun : configuration, autoload, helpers.
 */
define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

final class App
{
    public static $config = [];

    public static function cfg(string $key, $default = null)
    {
        $v = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }

    public static function dataDir(): string
    {
        $dir = APP_ROOT . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Fusion récursive : les tableaux associatifs sont fusionnés, les listes sont remplacées. */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $isAssoc = is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::merge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }
}

// MCSTATS_CONFIG permet d'utiliser un fichier de configuration situé ailleurs
$configFile = getenv('MCSTATS_CONFIG') ?: APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo "config.php introuvable : copiez config.sample.php en config.php puis adaptez-le.";
    exit(1);
}

$userConfig = require $configFile;
App::$config = App::merge(require APP_ROOT . '/config.sample.php', is_array($userConfig) ? $userConfig : []);
unset($userConfig, $configFile);
date_default_timezone_set(App::cfg('timezone', 'Europe/Paris'));

spl_autoload_register(function ($class) {
    $file = APP_ROOT . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require APP_ROOT . '/src/helpers.php';

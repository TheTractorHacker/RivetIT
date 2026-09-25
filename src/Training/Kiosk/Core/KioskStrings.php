<?php

namespace ITFlow\Training\Kiosk\Core;

/**
 * Kiosk UI strings, EN and ES (P3 spec §5.1). One file per lane under src/Training/Kiosk/Strings/
 * (core.php K1, signin.php K2, learn.php K3, learn_ui.php K4, trainer.php K5), each returning
 * ['en' => [key => text], 'es' => [key => text]]. A key defined in two files is a load-time
 * LogicException, like the route files. Placeholders are {name}; t() substitutes plain text
 * (the client renders every string with textContent).
 */
final class KioskStrings
{
    public const LANGS = ['en', 'es'];

    /** @var array{en:array<string,string>, es:array<string,string>}|null */
    private static ?array $all = null;

    /** @return array{en:array<string,string>, es:array<string,string>} */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $merged = ['en' => [], 'es' => []];
        $owner = [];
        $files = glob(dirname(__DIR__) . '/Strings/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $table = require $file;
            if (!is_array($table)) {
                throw new \LogicException('Kiosk strings file ' . basename($file) . ' must return an array');
            }
            foreach (self::LANGS as $lang) {
                foreach (($table[$lang] ?? []) as $key => $text) {
                    if (!is_string($key) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $key) !== 1 || !is_string($text)) {
                        throw new \LogicException("Kiosk string '$key' in " . basename($file) . ' is malformed');
                    }
                    if (isset($owner[$lang][$key])) {
                        throw new \LogicException("Kiosk string '$key' ($lang) is defined twice (" . $owner[$lang][$key] . ', ' . basename($file) . ')');
                    }
                    $owner[$lang][$key] = basename($file);
                    $merged[$lang][$key] = $text;
                }
            }
        }
        return self::$all = $merged;
    }

    public static function t(string $lang, string $key, array $vars = []): string
    {
        $all = self::all();
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'en';
        $text = $all[$lang][$key] ?? $all['en'][$key] ?? $key;
        if ($vars !== []) {
            $text = preg_replace_callback('/\{([a-z_]+)\}/', static fn(array $m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text) ?? $text;
        }
        return $text;
    }

    /** A known UI language or 'en'. */
    public static function lang(?string $lang): string
    {
        return in_array($lang, self::LANGS, true) ? (string) $lang : 'en';
    }
}

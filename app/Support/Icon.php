<?php

namespace App\Support;

/** Lucide SVGs from resources/svg/icons, read once per process and cached in memory. */
class Icon
{
    private static array $cache = [];

    /** Full <svg> markup for a name, or null when the icon file does not exist. */
    public static function svg(string $name): ?string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            return null; // never build a path from arbitrary input
        }
        if (! array_key_exists($name, self::$cache)) {
            $f = resource_path("svg/icons/{$name}.svg");
            self::$cache[$name] = is_file($f) ? trim(file_get_contents($f)) : null;
        }

        return self::$cache[$name];
    }
}

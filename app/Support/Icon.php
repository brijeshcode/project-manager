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

    public static function exists(?string $name): bool
    {
        return $name !== null && self::svg($name) !== null;
    }

    /** The set offered by the icon picker (all of them live in resources/svg/icons). */
    public const PICKER = [
        'square-check-big', 'circle-dot', 'sparkles', 'bug', 'arrow-left-right', 'list-checks', 'tag', 'palette',
        'star', 'flag', 'zap', 'lightbulb', 'wrench', 'rocket', 'message-square', 'bookmark', 'heart', 'code',
        'server', 'book-open', 'circle-help', 'triangle-alert', 'ban', 'layout-dashboard', 'eye', 'shield', 'settings', 'file-text',
    ];

    /** Validation rules for an icon field: empty, a known icon name, or a short plain-text emoji (never markup). */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:16', fn ($a, $v, $fail) => preg_match('/[<>&"\']/', (string) $v) ? $fail('The icon may not contain markup.') : null];
    }
}

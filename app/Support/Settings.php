<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Key/value settings with an optional group. Reads are cached for the request (the cache lives in the container, so tests and Octane start clean). */
class Settings
{
    public const PAGE_SIZES = [10, 15, 25, 50, 100];

    public const SIDEBARS = ['full' => 'Full', 'half' => 'Half (icons only)', 'mini' => 'Mini (menu button)'];

    public const NUMBER_FORMATS = ['international' => 'International — 1,234,567.00 · K, M', 'indian' => 'Indian — 12,34,567.00 · K, L, Cr'];

    /** Group a key is stored under when the caller names none. */
    private const GROUPS = ['local_currency' => 'currency', 'page_size' => 'system', 'sidebar_layout' => 'system', 'number_format' => 'system', 'dashboard_default_tab' => 'system'];

    public const DASHBOARD_TABS = ['financial' => 'Financial', 'project' => 'Project'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $c = app()->make('settings.cache');
        if (! isset($c[$key])) {
            $c[$key] = [DB::table('settings')->where('key', $key)->value('value')];
        }

        return $c[$key][0] ?? $default;
    }

    public static function set(string $key, string $value, ?string $group = null): void
    {
        DB::table('settings')->upsert(
            [['key' => $key, 'value' => $value, 'group' => $group ?? self::GROUPS[$key] ?? 'general', 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'group', 'updated_at'],
        );
        app()->make('settings.cache')[$key] = [$value];
    }

    /** All settings of one group, key => value. */
    public static function group(string $group): array
    {
        return DB::table('settings')->where('group', $group)->pluck('value', 'key')->all();
    }

    /** Rows per page on every paginated list. */
    public static function pageSize(): int
    {
        $n = (int) self::get('page_size', '15');

        return in_array($n, self::PAGE_SIZES, true) ? $n : 15;
    }

    /** Digit grouping for every amount: 'international' or 'indian'. */
    public static function numberFormat(): string
    {
        $v = self::get('number_format', 'international');

        return array_key_exists($v, self::NUMBER_FORMATS) ? $v : 'international';
    }

    public static function sidebarLayout(): string
    {
        $v = self::get('sidebar_layout', 'full');

        return array_key_exists($v, self::SIDEBARS) ? $v : 'full';
    }

    public static function dashboardDefaultTab(): string
    {
        $v = self::get('dashboard_default_tab', 'financial');

        return array_key_exists($v, self::DASHBOARD_TABS) ? $v : 'financial';
    }
}

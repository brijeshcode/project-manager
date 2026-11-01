<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        DB::table('settings')->upsert(
            [['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }
}

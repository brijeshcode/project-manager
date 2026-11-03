<?php

namespace App\Support;

/** Board column templates copied onto a project when it is created: [name, stage]. */
class BoardTemplates
{
    public const STAGES = ['not_started' => 'Not started', 'active' => 'Active', 'review' => 'Review', 'done' => 'Done'];

    public const ALL = [
        'software' => [
            ['Backlog', 'not_started'], ['Sprint plan', 'not_started'], ['In progress', 'active'], ['Blocked', 'active'],
            ['Testing', 'review'], ['Review', 'review'], ['Done', 'done'], ['Delivered', 'done'],
        ],
        'blank' => [['Backlog', 'not_started'], ['Done', 'done']],
    ];

    public static function columns(?string $key): array
    {
        return self::ALL[$key] ?? self::ALL['software'];
    }
}

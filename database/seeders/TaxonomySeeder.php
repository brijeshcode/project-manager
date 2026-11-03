<?php

namespace Database\Seeders;

use App\Models\Label;
use App\Models\TaskType;
use Illuminate\Database\Seeder;

/** Default task types and labels. Idempotent: existing rows (even edited ones) are left alone. */
class TaxonomySeeder extends Seeder
{
    public const LABELS = [
        'Urgent' => ['#ef4444', 'zap'],
        'Blocked' => ['#f97316', 'ban'],
        'Needs review' => ['#eab308', 'eye'],
        'Design' => ['#ec4899', 'palette'],
        'Frontend' => ['#06b6d4', 'layout-dashboard'],
        'Backend' => ['#6366f1', 'server'],
        'Documentation' => ['#10b981', 'book-open'],
        'Question' => ['#8b5cf6', 'circle-help'],
    ];

    public function run(): void
    {
        $i = 0;
        foreach (TaskType::DEFAULTS as $name => [$color, $icon]) {
            TaskType::firstOrCreate(['name' => $name], ['color' => $color, 'icon' => $icon, 'position' => ++$i, 'is_system' => true]);
        }
        foreach (self::LABELS as $name => [$color, $icon]) {
            Label::firstOrCreate(['name' => $name], ['color' => $color, 'icon' => $icon]);
        }
    }
}

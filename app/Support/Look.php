<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Colour rules shared by every page: stage badges, priority, deadline urgency. One place, reused by the ui components. */
class Look
{
    public const STAGE_COLORS = ['not_started' => 'slate', 'active' => 'brand', 'review' => 'amber', 'done' => 'green'];

    /** Priority value => [label, badge colour]. Tasks use it now, modules will later. */
    public const PRIORITIES = [0 => ['Low', 'slate'], 1 => ['Medium', 'amber'], 2 => ['High', 'rose']];

    public static function stageColor(?string $stage): string
    {
        return self::STAGE_COLORS[$stage] ?? 'slate';
    }

    /** @return array{0: string, 1: string} label and badge colour */
    public static function priority(int $value): array
    {
        return self::PRIORITIES[$value] ?? [(string) $value, 'slate'];
    }

    /**
     * Deadline urgency: none (no date, or work is done), late (past and not done), soon (0-3 days left,
     * today and tomorrow included), ok (further out). `days` is whole calendar days from today (negative = past).
     *
     * @return array{state: string, days: ?int}
     */
    public static function deadline(?Carbon $date, bool $done = false): array
    {
        if (! $date) {
            return ['state' => 'none', 'days' => null];
        }
        $days = (int) Carbon::today()->diffInDays($date->copy()->startOfDay(), false);
        $state = match (true) {
            $done => 'none',
            $days < 0 => 'late',
            $days <= 3 => 'soon',
            default => 'ok',
        };

        return ['state' => $state, 'days' => $days];
    }
}

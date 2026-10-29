<?php

namespace App\Services;

use App\Models\Project;
use Carbon\CarbonInterface;
use Carbon\Carbon;

class EstimateService
{
    private const HOURS_PER_DAY = 8.0;

    /**
     * Given a start date and a number of hours, return the working day on which
     * those hours are consumed (Mon–Fri only, 8h/day, weekends skipped).
     *
     * Convention: end-of-span lands ON the day the last hour is consumed.
     * - 8h from Monday  → Monday  (fills the day exactly, no overflow)
     * - 9h from Monday  → Tuesday (1h spills into the next day)
     * - 16h from Friday → Monday  (Fri fills 8h, weekend skipped, Mon fills 8h)
     * - 0h              → start date itself
     *
     * ponytail: O(days) walk; fine for project timelines (months, not decades).
     */
    public function workingDaysFrom(CarbonInterface $start, float $hours): CarbonInterface
    {
        $day = $start->copy()->startOfDay();

        // Advance past any non-working start day.
        while ($day->isWeekend()) {
            $day->addDay();
        }

        $remaining = $hours;

        // Each iteration: consume one working day (up to 8h).
        // Stop when remaining ≤ 0 — we're on the day the last hour lands.
        while ($remaining > self::HOURS_PER_DAY) {
            $remaining -= self::HOURS_PER_DAY;
            $day->addDay();
            while ($day->isWeekend()) {
                $day->addDay();
            }
        }

        return $day;
    }

    /**
     * Compute the project deadline from start_date.
     *
     * Steps:
     * 1. Sum all modules' totalEstimateHours (each module = sum of its tasks' totalEstimateHours).
     * 2. Convert hours → working days via workingDaysFrom.
     * 3. Apply buffer_percent AFTER the hours→days conversion:
     *    buffered_hours = total_hours × (1 + buffer_percent / 100).
     *
     * If start_date is null, falls back to today.
     */
    public function projectDeadline(Project $project): CarbonInterface
    {
        $project->loadMissing('modules.tasks.subtasks');

        $totalHours = 0.0;
        foreach ($project->modules as $module) {
            $totalHours += $module->timelineHours();
        }

        $buffer      = (float) ($project->buffer_percent ?? 0.0);
        $buffered    = $totalHours * (1 + $buffer / 100);

        $start = $project->start_date
            ? Carbon::parse($project->start_date)
            : Carbon::today();

        return $this->workingDaysFrom($start, $buffered);
    }
}

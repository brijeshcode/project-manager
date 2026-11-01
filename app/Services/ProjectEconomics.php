<?php

namespace App\Services;

use App\Enums\CostRateType;
use App\Models\BillableLine;
use App\Models\Payment;
use App\Models\Project;
use App\Support\Money;

class ProjectEconomics
{
    public static function for(Project $project): array
    {
        $project->loadMissing(
            'client',
            'modules.tasks.timeEntries.user',
            'modules.tasks.subtasks.timeEntries.user',
        );

        // Same traversal as Project::estimateVsActual(): tasks' entries plus their subtasks' entries.
        $entries = collect();
        foreach ($project->modules as $module) {
            foreach ($module->tasks as $task) {
                $entries = $entries->concat($task->timeEntries);
                foreach ($task->subtasks as $subtask) {
                    $entries = $entries->concat($subtask->timeEntries);
                }
            }
        }

        // ponytail: loads a project's entries into memory; move to SQL SUM grouped by user at ~100k entries.
        // Rulings: each entry's hours are rounded to 0.01 h before summing (integer hundredths, no float
        // sums); entries on draft tasks count, same as Project::estimateVsActual().
        $byUser = $entries->groupBy('user_id');
        $hundredths = $byUser->map(fn ($g) => $g->sum(fn ($e) => (int) round($e->hours * 100)))->all();

        // Cost rates are assumed to be in the client's currency (single-currency ceiling; no conversion).
        $cost = 0;
        foreach ($hundredths as $userId => $h100) {
            $user = $byUser[$userId]->first()->user; // time_entries.user_id cascades on delete: never null
            if ($user->cost_rate_type === null || $user->cost_rate_amount === null) {
                continue;
            }
            $rateCents = Money::toCents(number_format((float) $user->cost_rate_amount, 2, '.', ''));
            $cost += $user->cost_rate_type === CostRateType::Flat
                ? $rateCents // flat: once per project, however many entries
                : intdiv($h100 * $rateCents + 50, 100); // hundredths x cents, half-up
        }

        $totalH100 = array_sum($hundredths);

        $invoiced = (int) BillableLine::where('project_id', $project->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', 'sent'))
            ->sum('amount_cents');

        $received = (int) Payment::where('project_id', $project->id)
            ->where('client_id', $project->client_id)
            ->sum('amount_cents');

        // cents per hour = cents * 100 / hundredths, half-up
        $perHour = fn (int $cents) => $totalH100 === 0 ? null : intdiv($cents * 200 + $totalH100, 2 * $totalH100);

        return [
            'hours'                => $totalH100 / 100.0,
            'invoiced_cents'       => $invoiced,
            'received_cents'       => $received,
            'cost_cents'           => $cost,
            'profit_cents'         => $received - $cost,
            'hourly_received_cents' => $perHour($received),
            'hourly_invoiced_cents' => $perHour($invoiced),
            'currency'             => $project->client->currency,
        ];
    }
}

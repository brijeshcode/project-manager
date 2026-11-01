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

        $money = self::money($project);
        $invoiced = $money['invoiced_cents'];
        $received = $money['received_cents'];

        // cents per hour = cents * 100 / hundredths, half-up
        $perHour = fn (int $cents) => $totalH100 === 0 ? null : intdiv($cents * 200 + $totalH100, 2 * $totalH100);

        return [
            'hours'                => $totalH100 / 100.0,
            'cost_cents'           => $cost,
            'profit_cents'         => $received - $cost,
            'hourly_received_cents' => $perHour($received),
            'hourly_invoiced_cents' => $perHour($invoiced),
            'currency'             => $project->client->currency,
        ] + $money;
    }

    /**
     * Invoiced (sent invoices' lines on the project) and received, in client currency plus local currency.
     * invoiced_local converts each invoice's lines at its frozen rate (null-rate invoices skipped, counted);
     * received_local sums stored local amounts recorded in the current local currency (NULL-local counted).
     */
    public static function money(Project $project): array
    {
        $same = strtoupper($project->client->currency) === LocalCurrency::code();

        $invoiced = 0;
        $invoicedLocal = 0;
        $invoicedMissing = 0;
        $rows = BillableLine::query()
            ->join('invoices', 'invoices.id', '=', 'billable_lines.invoice_id')
            ->where('billable_lines.project_id', $project->id)
            ->where('invoices.status', 'sent')
            ->groupBy('invoices.id', 'invoices.exchange_rate')
            ->selectRaw('invoices.exchange_rate as rate, SUM(billable_lines.amount_cents) as s')
            ->get();
        foreach ($rows as $r) {
            $invoiced += (int) $r->s;
            $rate = $r->rate ?? ($same ? '1' : null);
            $rate === null ? $invoicedMissing++ : $invoicedLocal += Money::convert((int) $r->s, $rate);
        }

        $p = Payment::where('project_id', $project->id)->where('client_id', $project->client_id)
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as a, COALESCE(SUM(CASE WHEN local_currency = ? THEN local_amount_cents ELSE 0 END), 0) as l, COALESCE(SUM(CASE WHEN local_amount_cents IS NULL OR local_currency <> ? THEN 1 ELSE 0 END), 0) as m', [LocalCurrency::code(), LocalCurrency::code()])
            ->first();

        return [
            'invoiced_cents'          => $invoiced,
            'received_cents'          => (int) $p->a,
            'invoiced_local'          => $invoicedLocal,
            'received_local'          => (int) $p->l,
            'invoiced_missing_rate'   => $invoicedMissing,
            'received_missing_rate'   => (int) $p->m,
        ];
    }
}

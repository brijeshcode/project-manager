<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Enums\BoardColumn;
use App\Enums\TaskStatus;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillableService
{
    /** Idempotent: one uninvoiced-or-invoiced line per Done, published, billable task. Invoiced lines are never touched. */
    public function syncCompletedWork(Client $client): void
    {
        $tasks = Task::query()
            ->whereHas('module.project', fn ($q) => $q->where('client_id', $client->id))
            ->where('status', TaskStatus::Published->value)
            ->where('board_column', BoardColumn::Done->value)
            ->with(['module.project', 'subtasks'])
            ->get();

        $hourly = $client->billing_mode === BillingMode::Hourly;

        DB::transaction(function () use ($tasks, $hourly, $client) {
            // read inside the transaction; lockForUpdate is a no-op on sqlite (no unique index, per ruling)
            $existing = BillableLine::where('client_id', $client->id)->where('source_type', Task::class)
                ->orderBy('id')->lockForUpdate()->get()->groupBy('source_id');
            $keep = [];
            foreach ($tasks as $task) {
                $cents = $this->amountCents($task, $hourly);
                if ($cents === null) {
                    continue;
                }
                $keep[] = $task->id;
                $data = ['description' => $task->title, 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents];
                $group = $existing->get($task->id, collect());
                $line = $group->first(fn ($l) => $l->invoice_id === null) ?? $group->first();
                // self-heal: surplus uninvoiced duplicates are deleted, the first one is kept
                $group->filter(fn ($l) => $l->invoice_id === null && $l->id !== $line?->id)->each->delete();
                if ($line === null) {
                    BillableLine::create($data + [
                        'client_id' => $client->id, 'project_id' => $task->module->project_id,
                        'source_type' => Task::class, 'source_id' => $task->id,
                    ]);
                } elseif ($line->invoice_id === null) {
                    $line->update($data);
                }
            }

            BillableLine::where('client_id', $client->id)->where('source_type', Task::class)
                ->whereNull('invoice_id')->whereNotIn('source_id', $keep)->delete();
        });
    }

    public function pendingFor(Client $client): Collection
    {
        return BillableLine::pending()->where('client_id', $client->id)->orderBy('id')->get();
    }

    private function amountCents(Task $task, bool $hourly): ?int
    {
        if ($task->price !== null && $task->price > 0) {
            return Money::toCents((string) $task->price);
        }
        $rate = $task->module->hourly_rate_cents;
        if (! $hourly || $rate === null) {
            return null;
        }
        $hours = $task->effectiveHours() + (float) $task->subtasks->sum('estimate_hours'); // subtasks eager-loaded, no N+1
        $hoursX100 = Money::toCents(number_format($hours, 2, '.', ''));

        return intdiv($hoursX100 * $rate + 50, 100);
    }
}

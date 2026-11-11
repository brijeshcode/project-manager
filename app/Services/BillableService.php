<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Sprint;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillableService
{
    /**
     * Idempotent. Every done, published, billable task has ONE line ("KEY-n Title") until it is invoiced; grouping into
     * "Sprint 3 (Nov 4 – Nov 18): completed NWS-12, NWS-15" happens on the invoice (BillableLine::invoice_label).
     * Invoiced lines are never touched. Legacy: a task named in an invoiced per-sprint line is already billed.
     */
    public function syncCompletedWork(Client $client): void
    {
        $tasks = Task::query()
            ->whereHas('module.project', fn ($q) => $q->where('client_id', $client->id))
            ->where('status', TaskStatus::Published->value)->approved()
            ->whereHas('column', fn ($q) => $q->where('stage', 'done'))
            ->with(['module.project', 'subtasks', 'sprint'])
            ->get();

        DB::transaction(function () use ($tasks, $client) {
            $sprintLines = BillableLine::where('client_id', $client->id)->where('source_type', Sprint::class)->lockForUpdate()->get();
            BillableLine::whereIn('id', $sprintLines->whereNull('invoice_id')->pluck('id'))->delete();
            $billedInSprint = $sprintLines->whereNotNull('invoice_id')->groupBy('source_id');
            $tasks = $tasks->reject(fn (Task $t) => $billedInSprint->get($t->sprint_id)?->contains(
                fn ($l) => preg_match('/\b'.preg_quote($t->code(), '/').'\b/', $l->description)
            ));

            // read inside the transaction; lockForUpdate is a no-op on sqlite (no unique index, per ruling)
            $existing = BillableLine::where('client_id', $client->id)->where('source_type', Task::class)
                ->orderBy('id')->lockForUpdate()->get()->groupBy('source_id');
            $keep = [];
            foreach ($tasks as $task) {
                $cents = $this->amountCents($task);
                if ($cents === null) {
                    continue;
                }
                $keep[] = $task->id;
                $data = ['description' => $task->code().' '.$task->title, 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents];
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

    /** Pending task lines with their task, module and sprint, for the invoice work picker. */
    public function pendingTaskLines(Client $client): Collection
    {
        $lines = BillableLine::pending()->where('client_id', $client->id)->where('source_type', Task::class)->orderBy('id')->get();
        $tasks = Task::with(['module.project', 'project', 'sprint'])->whereIn('id', $lines->pluck('source_id'))->get()->keyBy('id');

        return $lines->each(fn ($l) => $l->setRelation('task', $tasks->get($l->source_id)))->filter(fn ($l) => $l->task !== null)->values();
    }

    public function pendingFor(Client $client): Collection
    {
        return BillableLine::pending()->where('client_id', $client->id)->orderBy('id')->get();
    }

    /** A task's value in cents: always its stored amount (typed when fixed, derived from billed hours when hourly); null = unpriced. */
    private function amountCents(Task $task): ?int
    {
        return $task->budget_amount !== null && $task->budget_amount > 0 ? Money::toCents((string) $task->budget_amount) : null;
    }
}

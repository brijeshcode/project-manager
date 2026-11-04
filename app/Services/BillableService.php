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
     * Idempotent. Done, published, billable tasks are billed as their own line ("KEY-n Title"), EXCEPT the done tasks
     * of a COMPLETED sprint: those are ONE line per sprint ("Sprint 3 (Nov 4 – Nov 18): completed NWS-12, NWS-15").
     * A task is never in two lines: when a sprint completes its pending per-task lines are replaced by the sprint
     * line, and a task that already has an invoiced line stays out of the sprint line. Invoiced lines are never
     * touched (a sprint line is frozen once invoiced; a task done in that sprint LATER, i.e. not named in the frozen
     * line, bills on its own).
     */
    public function syncCompletedWork(Client $client): void
    {
        $tasks = Task::query()
            ->whereHas('module.project', fn ($q) => $q->where('client_id', $client->id))
            ->where('status', TaskStatus::Published->value)
            ->whereHas('column', fn ($q) => $q->where('stage', 'done'))
            ->with(['module.project', 'subtasks', 'sprint'])
            ->get();

        DB::transaction(function () use ($tasks, $client) {
            $sprintLines = BillableLine::where('client_id', $client->id)->where('source_type', Sprint::class)->orderBy('id')->lockForUpdate()->get()->keyBy('source_id');
            $invoicedTaskIds = BillableLine::where('client_id', $client->id)->where('source_type', Task::class)->whereNotNull('invoice_id')->pluck('source_id');
            // tasks that go into a sprint line: done in a completed sprint whose line is not invoiced yet, and not invoiced on their own
            $inSprint = $tasks->filter(fn (Task $t) => $t->sprint?->status === 'completed' && ! $sprintLines->get($t->sprint_id)?->invoice_id && ! $invoicedTaskIds->contains($t->id));
            $frozen = $tasks->filter(fn (Task $t) => $t->sprint?->status === 'completed' && $sprintLines->get($t->sprint_id)?->invoice_id);
            $lateOnly = $frozen->reject(fn (Task $t) => preg_match('/\b'.preg_quote($t->code(), '/').'\b/', $sprintLines->get($t->sprint_id)->description));
            $this->syncSprintLines($client, $inSprint, $sprintLines);
            $tasks = $tasks->filter(fn (Task $t) => $t->sprint?->status !== 'completed' || $lateOnly->contains($t) || $invoicedTaskIds->contains($t->id));

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

    /** One pending line per completed sprint holding the value of its done tasks; a sprint with nothing billable has none. */
    private function syncSprintLines(Client $client, Collection $inSprint, Collection $existing): void
    {
        $keep = [];
        foreach ($inSprint->groupBy('sprint_id') as $sprintId => $group) {
            $priced = $group->map(fn (Task $t) => [$t, $this->amountCents($t)])->filter(fn ($p) => $p[1] !== null)->values();
            if ($priced->isEmpty()) {
                continue;
            }
            $sprint = $group->first()->sprint;
            $cents = $priced->sum(fn ($p) => $p[1]);
            $keep[] = $sprintId;
            $data = [
                'description' => $sprint->name.' ('.$sprint->starts_on->format('M j').' – '.$sprint->ends_on->format('M j').'): completed '.$priced->map(fn ($p) => $p[0]->code())->sortBy(fn ($c) => (int) substr($c, strrpos($c, '-') + 1))->implode(', '),
                'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents,
            ];
            $line = $existing->get($sprintId);
            $line === null
                ? BillableLine::create($data + ['client_id' => $client->id, 'project_id' => $sprint->project_id, 'source_type' => Sprint::class, 'source_id' => $sprintId])
                : ($line->invoice_id === null && $line->update($data));
        }
        BillableLine::where('client_id', $client->id)->where('source_type', Sprint::class)->whereNull('invoice_id')->whereNotIn('source_id', $keep)->delete();
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

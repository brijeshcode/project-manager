<?php

namespace App\Services;

use App\Exceptions\OverBudget;
use App\Models\Module;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * A module's budget arithmetic; every surface (module page, task page, forms, sprints) calls it. Money (cents) is
 * the cap, always: the module's budget_amount (typed for a fixed module, hours x rate for an hourly one). A task's
 * value is its stored budget_amount (typed for a fixed task, derived from billed hours for an hourly task).
 * Used = Σ values of the tasks that count (published, not ideas). Only manual FIXED edits are ever blocked (guard());
 * derived hourly values and time logging never are: the panel just shows how much the module is over.
 */
class ModuleBudget
{
    public function __construct(public readonly Module $module) {}

    public function hourly(): bool
    {
        return $this->module->billingMode() === 'hourly';
    }

    /** Tasks that use up the budget, with what the maths needs loaded. */
    public function budgeted(): Collection
    {
        return Task::where('module_id', $this->module->id)->budgeted()->with(['column', 'subtasks', 'module.project'])->orderBy('position')->orderBy('id')->get();
    }

    /** The cap in cents, null when the module has no amount yet. */
    public function cap(): ?int
    {
        $a = ($this->module->fresh() ?? $this->module)->budget_amount;

        return $a === null ? null : Money::toCents((string) $a);
    }

    /** A task's value in cents; unpriced = 0. */
    public function value(Task $t): int
    {
        return $t->budget_amount === null ? 0 : Money::toCents((string) $t->budget_amount);
    }

    public function priced(Task $t): bool
    {
        return $t->budget_amount !== null;
    }

    public function used(): int
    {
        return $this->budgeted()->sum(fn (Task $t) => $this->value($t));
    }

    /** Cap minus used; negative = over, null = no cap. */
    public function remaining(): ?int
    {
        $cap = $this->cap();

        return $cap === null ? null : $cap - $this->used();
    }

    /** Σ values (cents) of budgeted tasks sitting in a done-stage column. */
    public function doneValue(): int
    {
        return $this->budgeted()->filter(fn (Task $t) => $t->column?->stage === 'done')->sum(fn (Task $t) => $this->value($t));
    }

    /** How many cents over the cap the module would be if task `$excludeId` (null = a new task) were worth `$newCents`. */
    public function wouldExceed(?int $excludeId, int $newCents): int
    {
        $cap = $this->cap();
        if ($cap === null) {
            return 0;
        }
        $others = $this->budgeted()->reject(fn (Task $t) => $t->id === $excludeId)->sum(fn (Task $t) => $this->value($t));

        return max(0, $others + $newCents - $cap);
    }

    /** Throws OverBudget when a manual FIXED edit raises what the task uses and the module would end over its cap. */
    public function guard(?Task $existing, int $newCents): void
    {
        $current = $existing && $existing->exists && Task::find($existing->id)?->countsTowardBudget() ? $this->value(Task::find($existing->id)) : 0;
        if ($newCents <= $current) {
            return; // never blocks an edit that lowers or keeps what the task uses
        }
        if ($over = $this->wouldExceed($existing?->id, $newCents)) {
            throw new OverBudget($this->module, $over, ($this->cap() ?? 0) + $over);
        }
    }

    /** Local-currency equivalent of a money amount in cents; null when the project currency has no rate. */
    public function localCents(int $cents): ?int
    {
        $rate = LocalCurrency::rateFor((string) $this->module->project->currency);

        return $rate === null ? null : Money::convert($cents, $rate);
    }

    /** Everything the module panel and the Modules-tab line show (cents). */
    public function summary(): array
    {
        $tasks = $this->budgeted();
        $used = $tasks->sum(fn (Task $t) => $this->value($t));
        $cap = $this->cap();

        return [
            'cap' => $cap, 'used' => $used, 'remaining' => $cap === null ? null : $cap - $used,
            'unpriced' => $tasks->filter(fn (Task $t) => $t->budget_amount === null)->count(),
            'done' => $tasks->filter(fn (Task $t) => $t->column?->stage === 'done')->sum(fn (Task $t) => $this->value($t)),
        ];
    }

    public function format(int $cents): string
    {
        return Money::format($cents, $this->module->project->currency);
    }
}

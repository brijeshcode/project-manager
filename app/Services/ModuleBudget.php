<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Exceptions\OverBudget;
use App\Models\Module;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place that knows a module's budget arithmetic; every surface (module page, task page, forms, billing,
 * seeders) calls it. Units: cents for a fixed-price client; hundredths of an hour for an hourly client (the cap is
 * then the module's budget hours). Task values are STORED (tasks.budget_amount) and never rewritten silently:
 * only distribute() fills unpriced tasks and rebalance() rewrites others, and both run on an explicit confirm.
 *
 * Allocated = tasks that use up the budget: published, non-idea tasks that are in scope, plus ACCEPTED add-ons.
 * A proposed add-on is exempt from the cap until it is accepted.
 */
class ModuleBudget
{
    public function __construct(public readonly Module $module) {}

    public function hourly(): bool
    {
        return $this->module->project->client->billing_mode === BillingMode::Hourly;
    }

    /** Tasks that use up the budget, with what the maths needs loaded. */
    public function budgeted(): Collection
    {
        return Task::where('module_id', $this->module->id)->budgeted()->with(['column', 'subtasks', 'module.project'])->orderBy('position')->orderBy('id')->get();
    }

    /** The cap in units (cents / hundredths of an hour), null when the module has no budget yet. */
    public function cap(): ?int
    {
        $m = $this->module->fresh() ?? $this->module;
        if ($this->hourly()) {
            return $m->budget_hours === null ? null : (int) round((float) $m->budget_hours * 100);
        }

        return $m->budget_amount === null ? null : Money::toCents((string) $m->budget_amount);
    }

    /** What a task uses of the cap: its stored value (fixed; none = 0) or its hours (hourly). */
    public function units(Task $t): int
    {
        if ($this->hourly()) {
            return (int) round($t->loadMissing('subtasks')->hours() * 100);
        }

        return $t->budget_amount === null ? 0 : Money::toCents((string) $t->budget_amount);
    }

    /** A task's money value in cents: stored amount (fixed) or hours x the module's hourly rate (hourly). */
    public function valueCents(Task $t): int
    {
        if (! $this->hourly()) {
            return $this->units($t);
        }
        $rate = $t->module?->hourly_rate ?? $this->module->hourly_rate;

        return $rate === null ? 0 : intdiv($this->units($t) * Money::toCents((string) $rate) + 50, 100);
    }

    /** Fixed price: has the task been given a value yet? (Hourly tasks are always priced by their hours.) */
    public function priced(Task $t): bool
    {
        return $this->hourly() || $t->budget_amount !== null;
    }

    public function allocated(): int
    {
        return $this->budgeted()->sum(fn (Task $t) => $this->units($t));
    }

    /** Cap minus allocated; negative = over (legacy data), null = no cap. */
    public function remaining(): ?int
    {
        $cap = $this->cap();

        return $cap === null ? null : $cap - $this->allocated();
    }

    /** Σ values (cents) of budgeted tasks sitting in a done-stage column. */
    public function doneValue(): int
    {
        return $this->budgeted()->filter(fn (Task $t) => $t->column?->stage === 'done')->sum(fn (Task $t) => $this->valueCents($t));
    }

    /** Σ values (cents) of the budgeted tasks that are not unpriced; money view of allocated. */
    public function allocatedValue(): int
    {
        return $this->budgeted()->sum(fn (Task $t) => $this->valueCents($t));
    }

    /**
     * How many units over the cap the module would be if the task `$excludeId` (null = a new task) used `$newUnits`
     * instead of what it uses now. 0 = fits (also when there is no cap).
     */
    public function wouldExceed(?int $excludeId, int $newUnits): int
    {
        $cap = $this->cap();
        if ($cap === null) {
            return 0;
        }
        $others = $this->budgeted()->reject(fn (Task $t) => $t->id === $excludeId)->sum(fn (Task $t) => $this->units($t));

        return max(0, $others + $newUnits - $cap);
    }

    /** Throws OverBudget when the change raises what a task uses and the module would end over its cap. */
    public function guard(?Task $existing, int $newUnits): void
    {
        $current = $existing && $existing->exists && Task::find($existing->id)?->countsTowardBudget() ? $this->units(Task::find($existing->id)) : 0;
        if ($newUnits <= $current) {
            return; // never blocks an edit that lowers or keeps what the task uses
        }
        if ($over = $this->wouldExceed($existing?->id, $newUnits)) {
            throw new OverBudget($this->module, $over, ($this->cap() ?? 0) + $over);
        }
    }

    /** Local-currency equivalent of a money amount in cents; null when the project currency has no rate. */
    public function localCents(int $cents): ?int
    {
        $rate = LocalCurrency::rateFor((string) $this->module->project->currency);

        return $rate === null ? null : Money::convert($cents, $rate);
    }

    /** Everything the module panel and the Modules-tab line show (units; money in cents). */
    public function summary(): array
    {
        $tasks = $this->budgeted();
        $allocated = $tasks->sum(fn (Task $t) => $this->units($t));
        $cap = $this->cap();

        return [
            'hourly' => $this->hourly(), 'cap' => $cap, 'allocated' => $allocated,
            'remaining' => $cap === null ? null : $cap - $allocated,
            'unpriced' => $this->hourly() ? 0 : $tasks->filter(fn (Task $t) => $t->budget_amount === null)->count(),
            'done' => $tasks->filter(fn (Task $t) => $t->column?->stage === 'done')->sum(fn (Task $t) => $this->valueCents($t)),
            'proposed' => Task::where('module_id', $this->module->id)->where('scope', 'out')->whereNull('addon_accepted_at')->count(),
        ];
    }

    public function format(int $units): string
    {
        return $this->hourly() ? rtrim(rtrim(number_format($units / 100, 2), '0'), '.').' h' : Money::format($units, $this->module->project->currency);
    }

    /**
     * Proportional split of `$pool` cents over tasks by hours (`$weights` = [id => hundredths of an hour]).
     * Round half-up; the LAST task with hours absorbs the rounding remainder so the parts sum to the pool exactly.
     * Zero-hour tasks get 0, except when EVERY task has 0 hours: then they share equally (last absorbs the remainder).
     */
    public static function split(int $pool, array $weights): array
    {
        $ids = array_keys($weights);
        $out = array_fill_keys($ids, 0);
        if (! $ids) {
            return $out;
        }
        $total = array_sum($weights);
        if ($total <= 0) {
            $base = intdiv($pool, count($ids));
            foreach ($ids as $id) {
                $out[$id] = $base;
            }
            $out[end($ids)] = $pool - $base * (count($ids) - 1);

            return $out;
        }
        $last = array_key_last(array_filter($weights, fn ($w) => $w > 0));
        $left = $pool;
        foreach ($weights as $id => $w) {
            if ($w <= 0) {
                continue;
            }
            $out[$id] = $id === $last ? $left : min($left, intdiv($pool * $w * 2 + $total, 2 * $total));
            $left -= $out[$id];
        }

        return $out;
    }

    private function hoursX100(Task $t): int
    {
        return (int) round($t->loadMissing('subtasks')->hours() * 100);
    }

    /**
     * "Distribute remaining budget by hours": remaining = cap − Σ priced; each UNPRICED budgeted task gets its
     * hours share of it (see split()). Never touches priced tasks. Fixed-price clients only.
     * Returns ['ok', 'reason', 'remaining', 'rows' => [['task', 'hours', 'cents']]].
     */
    public function distributePreview(): array
    {
        $no = fn (string $why) => ['ok' => false, 'reason' => $why, 'remaining' => 0, 'rows' => []];
        if ($this->hourly()) {
            return $no('Hourly clients are billed by hours worked, so there is nothing to distribute.');
        }
        $cap = $this->cap();
        if ($cap === null) {
            return $no('Set the module budget first.');
        }
        $tasks = $this->budgeted();
        $unpriced = $tasks->filter(fn (Task $t) => $t->budget_amount === null)->values();
        $remaining = $cap - $tasks->sum(fn (Task $t) => $this->units($t));
        if ($unpriced->isEmpty()) {
            return $no('Every task already has a value.');
        }
        if ($remaining <= 0) {
            return $no('No budget remains to distribute.');
        }
        $parts = self::split($remaining, $unpriced->mapWithKeys(fn (Task $t) => [$t->id => $this->hoursX100($t)])->all());

        return ['ok' => true, 'reason' => null, 'remaining' => $remaining, 'rows' => $unpriced->map(fn (Task $t) => ['task' => $t, 'hours' => $this->hoursX100($t) / 100, 'cents' => $parts[$t->id]])->all()];
    }

    /** Applies the preview (recomputed, so it is always current). Returns how many tasks were priced. */
    public function applyDistribute(): int
    {
        return DB::transaction(function () {
            $p = $this->distributePreview();
            foreach ($p['rows'] as $r) {
                $r['task']->update(['budget_amount' => Money::toInput($r['cents'])]);
            }

            return count($p['rows']);
        });
    }

    /**
     * Make room by shrinking the OTHER tasks: the task `$taskId` (null = a new task, or the module budget being
     * lowered) takes `$newUnits`; done-stage tasks are never changed; the open PRICED others share what is left
     * of `$cap` (default: the module's) by their hours. Unpriced tasks stay unpriced. Fixed-price clients only.
     * Returns ['ok', 'reason', 'rows' => [['task', 'old', 'new']]].
     */
    public function rebalancePreview(?int $taskId, int $newUnits, ?int $cap = null): array
    {
        $no = fn (string $why) => ['ok' => false, 'reason' => $why, 'rows' => []];
        if ($this->hourly()) {
            return $no('Hourly clients are billed by hours worked, so values are not rebalanced.');
        }
        $cap ??= $this->cap();
        if ($cap === null) {
            return $no('The module has no budget.');
        }
        $others = $this->budgeted()->reject(fn (Task $t) => $t->id === $taskId);
        $locked = $others->filter(fn (Task $t) => $t->column?->stage === 'done')->sum(fn (Task $t) => $this->units($t));
        $open = $others->filter(fn (Task $t) => $t->column?->stage !== 'done' && $t->budget_amount !== null)->values();
        $pool = $cap - $newUnits - $locked;
        if ($open->isEmpty()) {
            return $no('There are no other open tasks with a value to rebalance.');
        }
        if ($pool < 0) {
            return $no('Done tasks keep their value, and with this change they alone already use more than the budget.');
        }
        $parts = self::split($pool, $open->mapWithKeys(fn (Task $t) => [$t->id => $this->hoursX100($t)])->all());

        return ['ok' => true, 'reason' => null, 'rows' => $open->map(fn (Task $t) => ['task' => $t, 'old' => $this->units($t), 'new' => $parts[$t->id]])->all()];
    }

    /** Applies the rebalance to the OTHER tasks only; the caller then saves the task (or the new cap) itself. */
    public function applyRebalance(?int $taskId, int $newUnits, ?int $cap = null): void
    {
        DB::transaction(function () use ($taskId, $newUnits, $cap) {
            $p = $this->rebalancePreview($taskId, $newUnits, $cap);
            if (! $p['ok']) {
                throw ValidationException::withMessages(['rebalance' => $p['reason']]);
            }
            foreach ($p['rows'] as $r) {
                $r['task']->update(['budget_amount' => Money::toInput($r['new'])]);
            }
        });
    }
}

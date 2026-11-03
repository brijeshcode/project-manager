<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Support\Money;
use App\Models\Concerns\FreezesLocalAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Module extends Model
{
    use FreezesLocalAmounts;

    protected $fillable = ['project_id', 'name', 'description', 'position', 'budget_hours', 'hourly_rate', 'budget_amount', 'deadline', 'exchange_rate', 'hourly_rate_local', 'budget_amount_local'];

    public const BUDGET_FIELDS = ['budget_hours', 'hourly_rate', 'budget_amount', 'deadline', 'exchange_rate', 'hourly_rate_local', 'budget_amount_local'];

    protected function casts(): array
    {
        return ['budget_hours' => 'decimal:2', 'hourly_rate' => 'decimal:2', 'budget_amount' => 'decimal:2', 'hourly_rate_local' => 'decimal:2', 'budget_amount_local' => 'decimal:2', 'deadline' => 'date'];
    }

    protected function localAmounts(): array
    {
        return ['hourly_rate' => ['hourly_rate_local', 'major'], 'budget_amount' => ['budget_amount_local', 'major']];
    }

    protected function moneyCurrency(): ?string
    {
        return $this->project?->currency;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(\App\Models\Task::class);
    }

    /** Hourly client: hours x rate; fixed client: the fixed amount. Null when not configured. */
    public function budgetAmount(): ?string
    {
        if ($this->project->client->billing_mode === BillingMode::Hourly) {
            if ($this->budget_hours === null || $this->hourly_rate === null) {
                return null;
            }
            $h = Money::toCents((string) $this->budget_hours);
            $rate = Money::toCents($this->hourly_rate);
            // absurd rows must not overflow int math (saveBudget bounds make this unreachable)
            if ((float) $h * $rate > PHP_INT_MAX / 2) {
                return null;
            }

            return Money::toInput(intdiv($h * $rate + 50, 100)); // hours x100, integer half-up
        }

        return $this->budget_amount;
    }

    /** Applies budget attrs; logs one row per changed field. Reason required when overwriting an existing value. */
    public function updateBudget(array $attrs, User $by, string $reason): void
    {
        $norm = fn ($f, $v) => $v === null || $v === '' ? null
            : ($f === 'deadline' ? \Illuminate\Support\Carbon::parse($v)->toDateString()
            : (in_array($f, ['budget_hours', 'hourly_rate', 'budget_amount', 'hourly_rate_local', 'budget_amount_local']) ? number_format((float) $v, 2, '.', '') : number_format((float) $v, 2, '.', '')));

        $changes = [];
        foreach (array_intersect_key($attrs, array_flip(self::BUDGET_FIELDS)) as $f => $new) {
            $old = $norm($f, $this->getAttribute($f));
            $new = $norm($f, $new);
            if ($old !== $new) {
                $changes[$f] = [$old, $new];
            }
        }

        if (collect($changes)->contains(fn ($c) => $c[0] !== null) && trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when changing an existing budget value.']);
        }

        DB::transaction(function () use ($changes, $by, $reason) {
            foreach ($changes as $f => [$old, $new]) {
                ModuleBudgetChange::create(['module_id' => $this->id, 'user_id' => $by->id, 'field' => $f, 'old' => $old, 'new' => $new, 'reason' => trim($reason) ?: null]);
                $this->{$f} = $new;
            }
            $this->save();
        });
    }

    /** Sum of all tasks' totalEstimateHours for this module. */
    public function timelineHours(): float
    {
        return (float) $this->tasks->sum(fn ($t) => $t->totalEstimateHours());
    }

    /** Returns the next position integer (1-based) within a project. */
    public static function nextPosition(int $projectId): int
    {
        return (int) static::where('project_id', $projectId)->max('position') + 1;
    }

    /** Persists a new ordering given an ordered array of IDs, scoped to a project. */
    public static function reorder(array $orderedIds, int $projectId): void
    {
        DB::transaction(function () use ($orderedIds, $projectId) {
            foreach ($orderedIds as $position => $id) {
                static::where('id', $id)
                    ->where('project_id', $projectId)
                    ->update(['position' => $position + 1]);
            }
        });
    }
}

<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Support\Money;
use App\Models\Concerns\FreezesLocalAmounts;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasLabels;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Module extends Model
{
    use FreezesLocalAmounts, HasAttachments, HasComments, HasLabels;
    use LogsActivity { activityValue as protected baseActivityValue; }

    /** Changes to these are written to the activity log. */
    public array $loggedFields = ['name', 'description', 'status_override', 'deadline', 'client_visible', 'priority'];

    protected $attributes = ['client_visible' => false, 'priority' => 1];

    public const OVERRIDES = ['on_hold', 'completed'];

    public const STATUS_LABELS = ['pending' => 'Pending', 'working' => 'Working', 'in_review' => 'In review', 'completed' => 'Completed', 'on_hold' => 'On hold'];

    public const STATUS_COLORS = ['pending' => 'slate', 'working' => 'brand', 'in_review' => 'amber', 'completed' => 'green', 'on_hold' => 'rose'];

    protected $fillable = ['project_id', 'name', 'description', 'position', 'priority', 'status_override', 'client_visible', 'billing_mode', 'budget_hours', 'hourly_rate', 'budget_amount', 'deadline', 'exchange_rate', 'hourly_rate_local', 'budget_amount_local'];

    public const BUDGET_FIELDS = ['billing_mode', 'budget_hours', 'hourly_rate', 'budget_amount', 'deadline', 'exchange_rate', 'hourly_rate_local', 'budget_amount_local'];

    protected function casts(): array
    {
        return ['budget_hours' => 'decimal:2', 'hourly_rate' => 'decimal:2', 'budget_amount' => 'decimal:2', 'hourly_rate_local' => 'decimal:2', 'budget_amount_local' => 'decimal:2', 'deadline' => 'date', 'client_visible' => 'boolean', 'priority' => 'integer'];
    }

    protected function localAmounts(): array
    {
        return ['hourly_rate' => ['hourly_rate_local', 'major'], 'budget_amount' => ['budget_amount_local', 'major']];
    }

    protected function moneyCurrency(): ?string
    {
        return $this->project?->currency;
    }

    protected function activityValue(mixed $v, ?string $field = null): ?string
    {
        return $field === 'priority' && $v !== null && $v !== '' ? (['Low', 'Medium', 'High'][(int) $v] ?? (string) $v) : $this->baseActivityValue($v, $field);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function budgetChanges(): HasMany
    {
        return $this->hasMany(ModuleBudgetChange::class);
    }

    /** Runs before the FreezesLocalAmounts hook, so a derived amount also gets its local twin. */
    protected static function booting(): void
    {
        static::saving(function (self $m) {
            $m->billing_mode ??= $m->project?->client?->billing_mode?->value ?? 'fixed';
            if ($m->billing_mode === 'hourly') {
                $m->budget_amount = $m->derivedAmount();
            }
        });
    }

    protected static function booted(): void
    {
        // tasks that bill by hours follow the module's mode and rate
        static::saved(function (self $m) {
            $m->wasChanged(['billing_mode', 'hourly_rate']) && $m->tasks()->get()->each->recalculateValue();
        });

        static::deleting(function (self $m) {
            $m->comments()->delete();
            $m->activities()->delete();
            $m->attachments->each->delete(); // also removes the files
        });
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'module_user');
    }

    public function accessProject(): Project
    {
        return $this->project;
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(\App\Models\Task::class);
    }

    /** Tasks that count toward status/progress: no ideas, drafts or out-of-scope work */
    public function countedTasks(array $with = ['column'])
    {
        return $this->tasks()->with($with)->counted()->get();
    }

    /** pending | working | in_review | completed, derived from the tasks' board-column stages. */
    public function derivedStatus(): string
    {
        $stages = $this->countedTasks()->map(fn ($t) => $t->column?->stage);

        if ($stages->isEmpty() || $stages->every(fn ($s) => $s === 'not_started')) {
            return 'pending';
        }
        if ($stages->every(fn ($s) => $s === 'done')) {
            return 'completed';
        }
        if ($stages->contains('active')) {
            return 'working';
        }

        // ponytail: not_started + review (nothing active) falls through to working
        return $stages->reject(fn ($s) => $s === 'done')->every(fn ($s) => $s === 'review') ? 'in_review' : 'working';
    }

    /** What the badge shows: a manual on_hold/completed override wins, otherwise the derived status. */
    public function status(): string
    {
        return $this->status_override ?? $this->derivedStatus();
    }

    /** Manual override: only on_hold or completed; null reopens (back to the derived status). */
    public function setStatusOverride(?string $override): void
    {
        if ($override !== null && ! in_array($override, self::OVERRIDES, true)) {
            throw new \InvalidArgumentException('Status can only be overridden to on_hold or completed.');
        }
        $this->update(['status_override' => $override]);
    }

    /** Tasks that would be left open by marking the module completed. */
    public function openTaskCount(): int
    {
        return $this->countedTasks()->filter(fn ($t) => $t->column?->stage !== 'done')->count();
    }

    /** Earliest task start (derived, never stored). */
    public function startedAt(): ?\Illuminate\Support\Carbon
    {
        $min = $this->tasks()->min('started_at');

        return $min ? \Illuminate\Support\Carbon::parse($min) : null;
    }

    /** Latest task completion, only once the module is completed (derived or manual). */
    public function endedAt(): ?\Illuminate\Support\Carbon
    {
        $max = $this->status() === 'completed' ? $this->tasks()->max('completed_at') : null;

        return $max ? \Illuminate\Support\Carbon::parse($max) : null;
    }

    /** fixed | hourly: the module's own mode (starts as its client's). */
    public function billingMode(): string
    {
        return $this->billing_mode ?: ($this->project?->client?->billing_mode?->value ?? 'fixed');
    }

    /** Hourly: the cap in major units = budget hours x rate (integer half-up); null until both are set. */
    public function derivedAmount(?string $hours = null, ?string $rate = null): ?string
    {
        $hours ??= $this->budget_hours;
        $rate ??= $this->hourly_rate;
        if ($hours === null || $rate === null || (float) $hours * (float) $rate > 999999999999) {
            return null; // absurd rows must not overflow the integer maths
        }

        return Money::toInput(intdiv(Money::toCents((string) $hours) * Money::toCents((string) $rate) + 50, 100));
    }

    /** The money cap: the fixed amount, or hours x rate (kept in budget_amount) for an hourly module. */
    public function budgetAmount(): ?string
    {
        return $this->budget_amount;
    }

    /** Applies budget attrs; logs one row per changed field. Reason required when overwriting an existing value. */
    public function updateBudget(array $attrs, User $by, string $reason): void
    {
        $norm = fn ($f, $v) => $v === null || $v === '' ? null
            : ($f === 'deadline' ? \Illuminate\Support\Carbon::parse($v)->toDateString()
            : ($f === 'billing_mode' ? (string) $v : number_format((float) $v, 2, '.', '')));

        // an hourly module's amount is always hours x rate: derive it so it is logged like any other change
        $mode = $attrs['billing_mode'] ?? $this->billingMode();
        if ($mode === 'hourly' && array_intersect(['billing_mode', 'budget_hours', 'hourly_rate'], array_keys($attrs))) {
            $attrs['budget_amount'] = $this->derivedAmount($attrs['budget_hours'] ?? $this->budget_hours, $attrs['hourly_rate'] ?? $this->hourly_rate);
        }

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

        $this->guardCap($changes, $mode);

        DB::transaction(function () use ($changes, $by, $reason) {
            foreach ($changes as $f => [$old, $new]) {
                ModuleBudgetChange::create(['module_id' => $this->id, 'user_id' => $by->id, 'field' => $f, 'old' => $old, 'new' => $new, 'reason' => trim($reason) ?: null]);
                $this->{$f} = $new;
            }
            $this->activityMute = ['deadline']; // the budget-change row already records it
            $this->save();
            $this->activityMute = [];
        });
    }

    /** Lowering a FIXED amount below what the tasks already use is refused (hourly amounts are derived and never block). */
    private function guardCap(array $changes, string $mode): void
    {
        if ($mode !== 'fixed' || ! isset($changes['budget_amount']) || $changes['budget_amount'][1] === null) {
            return;
        }
        [$old, $new] = [$changes['budget_amount'][0] === null ? null : Money::toCents($changes['budget_amount'][0]), Money::toCents($changes['budget_amount'][1])];
        $used = (new \App\Services\ModuleBudget($this))->used();
        if ($new < $used && ($old === null || $new < $old)) {
            throw new \App\Exceptions\OverBudget($this, $used - $new, $used, $new);
        }
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

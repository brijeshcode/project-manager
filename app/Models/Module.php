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

    protected $fillable = ['project_id', 'name', 'description', 'position', 'priority', 'status_override', 'client_visible', 'budget_hours', 'hourly_rate', 'budget_amount', 'deadline', 'exchange_rate', 'hourly_rate_local', 'budget_amount_local'];

    public const BUDGET_FIELDS = ['budget_hours', 'hourly_rate', 'budget_amount', 'deadline', 'exchange_rate', 'hourly_rate_local', 'budget_amount_local'];

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

    protected static function booted(): void
    {
        // the project's budget fields are calculated from its modules
        static::saved(function (self $m) {
            if ($m->wasRecentlyCreated || $m->wasChanged(['budget_amount', 'budget_hours', 'hourly_rate', 'project_id'])) {
                Project::find($m->project_id)?->recalculateBudget(); // not the cached relation: the module may just have moved
                $m->wasChanged('project_id') && Project::find($m->getOriginal('project_id'))?->recalculateBudget();
            }
        });
        static::deleted(fn (self $m) => Project::find($m->project_id)?->recalculateBudget());

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

        $this->guardCap($changes);

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

    /** Lowering the cap below what the tasks already use is refused (see ModuleBudget / OverBudget). */
    private function guardCap(array $changes): void
    {
        $mb = new \App\Services\ModuleBudget($this);
        $field = $mb->hourly() ? 'budget_hours' : 'budget_amount';
        if (! isset($changes[$field]) || $changes[$field][1] === null) {
            return;
        }
        $to = fn ($v) => $mb->hourly() ? (int) round((float) $v * 100) : Money::toCents($v);
        [$old, $new] = [$changes[$field][0] === null ? null : $to($changes[$field][0]), $to($changes[$field][1])];
        $allocated = $mb->allocated();
        if ($new < $allocated && ($old === null || $new < $old)) {
            throw new \App\Exceptions\OverBudget($this, $allocated - $new, $allocated, $new);
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

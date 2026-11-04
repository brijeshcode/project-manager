<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\FreezesLocalAmounts;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasLabels;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class Task extends Model
{
    use FreezesLocalAmounts, HasAttachments, HasComments, HasLabels;
    use LogsActivity { activityValue as protected baseActivityValue; }

    /** Changes to these are written to the activity log. */
    public array $loggedFields = ['title', 'description', 'deadline', 'priority', 'task_type_id', 'board_column_id', 'client_visible', 'estimate_hours', 'started_at', 'completed_at', 'sprint_id', 'billing_mode', 'hourly_rate', 'billable_hours'];

    protected $attributes = [
        'is_idea'        => false,
        'client_visible' => false,
        'testing_enabled' => false,
        'priority'       => 0,
        'scope'          => 'in',
        'estimate_hours' => 0.0,
        'position'       => 0,
    ];

    protected $fillable = [
        'module_id',
        'project_id',
        'creator_id',
        'task_type_id',
        'title',
        'description',
        'status',
        'is_idea',
        'priority',
        'estimate_hours',
        'scope',
        'budget_amount',
        'exchange_rate',
        'budget_amount_local',
        'billing_mode',
        'hourly_rate',
        'billable_hours',
        'client_visible',
        'testing_enabled',
        'testing_add_type',
        'testing_add_value',
        'board_column_id',
        'sprint_id',
        'position',
        'deadline',
        'started_at',
        'completed_at',
        'section',
        'client_approval',
    ];

    protected function casts(): array
    {
        return [
            'status'           => TaskStatus::class,
            'scope'            => TaskScope::class,
            'is_idea'          => 'boolean',
            'deadline'         => 'date',
            'started_at'       => 'date',
            'completed_at'     => 'date',
            'client_visible'   => 'boolean',
            'testing_enabled'  => 'boolean',
            'estimate_hours'   => 'float',
            'budget_amount'   => 'float',
            'billable_hours'  => 'float',
            'hourly_rate'     => 'decimal:2',
            'testing_add_value' => 'float',
        ];
    }

    /** Runs before the FreezesLocalAmounts hook, so a derived value also gets its local twin. */
    protected static function booting(): void
    {
        static::saving(function (self $t) {
            if ($t->billingMode() === 'hourly' && (! $t->exists || $t->isDirty(['billing_mode', 'hourly_rate', 'billable_hours', 'module_id', 'budget_amount']))) {
                $t->budget_amount = $t->derivedValue();
            }
        });
    }

    protected static function booted(): void
    {
        // a new task starts in its project's first column unless told otherwise
        static::creating(function (self $t) {
            // project_id follows the module; the number comes from the project's counter under a row lock:
            // unique per project, never reused (deleting a task never lowers the counter), never changed
            $t->project_id = Module::whereKey($t->module_id)->value('project_id');
            $t->number = DB::transaction(function () use ($t) {
                $p = Project::whereKey($t->project_id)->lockForUpdate()->firstOrFail();
                $p->increment('task_counter');

                return $p->task_counter;
            });
            $t->task_type_id ??= TaskType::default()?->id;
            $t->board_column_id ??= Module::find($t->module_id)?->project?->columns()->value('id');
            // one order per module: a new task goes to the bottom
            if (! $t->position) {
                $t->position = (int) static::where('module_id', $t->module_id)->max('position') + 1;
            }
            $t->syncDatesWithColumn();
        });

        static::updating(function (self $t) {
            if ($t->isDirty('board_column_id')) {
                $t->syncDatesWithColumn();
            }
        });

        static::deleting(function (self $t) {
            $t->subtasks->each->delete();
            $t->comments()->delete();
            $t->activities()->delete();
            $t->attachments->each->delete(); // also removes the files
        });
    }

    /**
     * Auto dates on a column change (a hand-edited date in the same save wins):
     * entering an active or done stage sets started_at when empty, entering done sets completed_at,
     * leaving done clears completed_at.
     */
    public function syncDatesWithColumn(): void
    {
        $stage = BoardColumn::find($this->board_column_id)?->stage;
        $today = now()->startOfDay();
        if (in_array($stage, ['active', 'done'], true) && ! $this->started_at && ! $this->isDirty('started_at')) {
            $this->started_at = $today;
        }
        if (! $this->isDirty('completed_at')) {
            $this->completed_at = $stage === 'done' ? ($this->completed_at ?? $today) : null;
        }
    }

    /** The task's project code, e.g. NWS-12. */
    public function code(): string
    {
        return ($this->project ?? $this->module->project)->key.'-'.$this->number;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Tasks matching a typed code (NWS-12, nws-12, 12) or title text. */
    public function scopeSearch(Builder $query, string $q): Builder
    {
        $q = trim($q);
        $like = '%'.addcslashes($q, '%_\\').'%';
        if (preg_match('/^(?:([A-Za-z0-9]{2,5})-)?(\d+)$/', $q, $m)) {
            return $query->where(fn ($w) => $w->where(fn ($c) => $c->where('tasks.number', (int) $m[2])->when($m[1] !== '', fn ($c) => $c->whereHas('project', fn ($p) => $p->where('key', strtoupper($m[1])))))->orWhere('tasks.title', 'like', $like));
        }

        return $query->where('tasks.title', 'like', $like);
    }

    /** Whole days from start to completion, null unless both dates exist. */
    public function daysTaken(): ?int
    {
        return $this->started_at && $this->completed_at ? (int) $this->started_at->diffInDays($this->completed_at) : null;
    }

    protected function localAmounts(): array
    {
        return ['budget_amount' => ['budget_amount_local', 'major']];
    }

    protected function moneyCurrency(): ?string
    {
        return $this->module?->project?->currency;
    }

    protected function activityValue(mixed $v, ?string $field = null): ?string
    {
        return match (true) {
            $v === null || $v === '' => null,
            $field === 'board_column_id' => BoardColumn::find($v)?->name ?? (string) $v,
            $field === 'sprint_id' => Sprint::find($v)?->name ?? (string) $v,
            $field === 'task_type_id' => TaskType::find($v)?->name ?? (string) $v,
            $field === 'priority' => ['Low', 'Medium', 'High'][(int) $v] ?? (string) $v,
            default => $this->baseActivityValue($v, $field),
        };
    }

    /** Fields a copy carries over (never comments, attachments, activity, column or position). */
    public const COPY_FIELDS = ['description', 'status', 'is_idea', 'priority', 'estimate_hours', 'scope', 'budget_amount', 'billing_mode', 'hourly_rate', 'client_visible', 'testing_enabled', 'testing_add_type', 'testing_add_value', 'deadline', 'section', 'task_type_id'];

    /** Copy into a module: new task in its first column, with labels, assignees and optionally subtasks. */
    public function copyTo(int $moduleId, User $by, bool $withSubtasks, ?string $title = null): static
    {
        $new = static::create(['module_id' => $moduleId, 'creator_id' => $by->id, 'title' => $title ?? $this->title] + $this->only(self::COPY_FIELDS));
        $new->assignees()->sync($this->assignees()->pluck('users.id')->all());
        $new->labels()->sync($this->labels()->pluck('labels.id')->all());
        $withSubtasks && $this->copySubtasksTo($new);

        return $new;
    }

    public function copySubtasksTo(self $new): void
    {
        foreach ($this->subtasks as $s) {
            $c = Subtask::create(['task_id' => $new->id, 'title' => $s->title, 'description' => $s->description, 'estimate_hours' => $s->estimate_hours]);
            $c->assignees()->sync($s->assignees()->pluck('users.id')->all());
            $c->labels()->sync($s->labels()->pluck('labels.id')->all());
        }
    }

    public function accessProject(): Project
    {
        return $this->module->project;
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TaskType::class, 'task_type_id');
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_user');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position');
    }

    public function checklistItems(): MorphMany
    {
        return $this->morphMany(ChecklistItem::class, 'checklistable')->orderBy('position');
    }

    public function timeEntries(): MorphMany
    {
        return $this->morphMany(TimeEntry::class, 'timeable');
    }

    /**
     * Effective hours: estimate + testing add-on when enabled.
     * percent → estimate * (1 + value/100); fixed → estimate + value.
     */
    public function effectiveHours(): float
    {
        if (! $this->testing_enabled) {
            return $this->estimate_hours;
        }

        return match ($this->testing_add_type) {
            'percent' => $this->estimate_hours * (1 + (float) ($this->testing_add_value ?? 0) / 100),
            'fixed'   => $this->estimate_hours + (float) ($this->testing_add_value ?? 0),
            default   => $this->estimate_hours,
        };
    }

    /** Same weight as totalEstimateHours() but from the loaded `subtasks` relation (no query). */
    public function hours(): float
    {
        return $this->effectiveHours() + (float) $this->subtasks->sum('estimate_hours');
    }

    /** Own effectiveHours + sum of all subtask estimate_hours. */
    public function totalEstimateHours(): float
    {
        return $this->effectiveHours() + (float) $this->subtasks()->sum('estimate_hours');
    }

    /**
     * Move this task to another column of its own project. `position` is the 1-based slot among this module's
     * tasks in that column; the module's single task order (tasks.position) is renumbered to match, so the
     * board and the module lists never disagree. A column from another project is silently ignored.
     */
    public function moveTo(int $columnId, int $position): void
    {
        if (! $this->module->project->columns()->whereKey($columnId)->exists()) {
            return;
        }

        DB::transaction(function () use ($columnId, $position) {
            $this->update(['board_column_id' => $columnId]);
            $others = static::where('module_id', $this->module_id)->whereKeyNot($this->id)->orderBy('position')->orderBy('id')->get(['id', 'board_column_id']);
            $inColumn = $others->where('board_column_id', $columnId)->values();
            $ids = $others->pluck('id')->all();
            if ($inColumn->isEmpty()) {
                $ids[] = $this->id; // nothing to be relative to: it goes last
            } elseif ($position - 1 < $inColumn->count()) {
                array_splice($ids, array_search($inColumn[max(0, $position - 1)]->id, $ids, true), 0, [$this->id]);
            } else {
                array_splice($ids, array_search($inColumn->last()->id, $ids, true) + 1, 0, [$this->id]);
            }
            static::renumber($ids);
        });
    }

    /**
     * Reorder a list that mixes modules (a sprint or backlog list). tasks.position is ONE order per module, so the
     * list order is applied per module: each module's tasks keep the order they have in the list and take over only
     * the slots they occupied. The relative order BETWEEN modules is not stored (lists group by module).
     */
    public static function reorderMixed(array $orderedIds): void
    {
        $ids = array_map('intval', $orderedIds);
        $byModule = static::whereIn('id', $ids)->pluck('module_id', 'id');
        $groups = [];
        foreach ($ids as $id) {
            isset($byModule[$id]) && $groups[$byModule[$id]][] = $id;
        }
        foreach ($groups as $moduleId => $group) {
            static::reorder($moduleId, $group);
        }
    }

    /** Write positions 1..n in the given order. */
    private static function renumber(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            static::whereKey($id)->update(['position' => $i + 1]);
        }
    }

    /**
     * Apply a drag-reorder of some of a module's tasks (a user may only see part of them): the given ids
     * take over, in the new order, the slots the same tasks occupied before; everything else stays put.
     */
    public static function reorder(int $moduleId, array $orderedIds): void
    {
        DB::transaction(function () use ($moduleId, $orderedIds) {
            $all = static::where('module_id', $moduleId)->orderBy('position')->orderBy('id')->pluck('id')->all();
            $ordered = array_values(array_unique(array_intersect(array_map('intval', $orderedIds), $all)));
            $queue = $ordered;
            $new = array_map(function ($id) use (&$queue, $ordered) {
                return in_array($id, $ordered, true) ? array_shift($queue) : $id;
            }, $all);
            static::renumber($new);
        });
    }

    /** fixed | hourly: this task's own override, else its module's mode. */
    public function billingMode(): string
    {
        return $this->billing_mode ?: ($this->module?->billingMode() ?? 'fixed');
    }

    /** True when the task is billed differently from its module. */
    public function billedDifferently(): bool
    {
        return $this->billing_mode !== null && $this->billing_mode !== $this->module?->billingMode();
    }

    /** The hourly rate that applies: the task's own, else its module's. */
    public function rate(): ?string
    {
        return $this->hourly_rate ?? $this->module?->hourly_rate;
    }

    /** Hours logged on the task and its subtasks. */
    public function loggedHours(): float
    {
        if (! $this->exists) {
            return 0.0;
        }
        $sub = TimeEntry::where('timeable_type', (new Subtask)->getMorphClass())->whereIn('timeable_id', Subtask::where('task_id', $this->id)->select('id'))->sum('hours');

        return round((float) $this->timeEntries()->sum('hours') + (float) $sub, 2);
    }

    /** Hours billed to the client: the hand-adjusted figure, else what was logged. */
    public function billedHours(): float
    {
        return $this->billable_hours !== null ? (float) $this->billable_hours : $this->loggedHours();
    }

    /** An hourly task's value (major units): billed hours x rate, integer half-up; null while no rate applies. */
    public function derivedValue(): ?string
    {
        $rate = $this->rate();
        if ($rate === null) {
            return null;
        }

        return Money::toInput(intdiv(Money::toCents(number_format($this->billedHours(), 2, '.', '')) * Money::toCents((string) $rate) + 50, 100));
    }

    /** Re-derive an hourly task's stored value (time logged, rate or mode changed); fixed amounts are never rewritten. */
    public function recalculateValue(): void
    {
        if ($this->billingMode() !== 'hourly') {
            return;
        }
        $v = $this->derivedValue();
        if ($v === null ? $this->budget_amount !== null : $this->budget_amount === null || Money::toCents($v) !== Money::toCents((string) $this->budget_amount)) {
            $this->forceFill(['budget_amount' => $v])->save();
        }
    }

    /** Does this task use up its module's budget? Published and not an idea (scope does not matter). */
    public function countsTowardBudget(): bool
    {
        return $this->status === TaskStatus::Published && ! $this->is_idea;
    }

    /** Tasks that use up the module budget. */
    public function scopeBudgeted(Builder $query): Builder
    {
        return $query->where('is_idea', false)->where('status', TaskStatus::Published->value);
    }

    /** Tasks that count toward module status/progress: no ideas, drafts or out-of-scope work. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('is_idea', false)->where('status', TaskStatus::Published->value)->where('scope', TaskScope::In->value);
    }

    /**
     * Scope: published tasks restricted to projects where the user is an active member;
     * drafts only to their creator regardless of membership.
     * super_admin sees all published tasks + own drafts.
     * Section filter: a member's access_scope restricts which task sections are visible
     * ('frontend' → frontend+null, 'backend' → backend+null, 'all' → everything).
     * Enforced at query level — Gate::before cannot override this.
     *
     * Membership sub-join mirrors ProjectPolicy::isMember.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $isSuperAdmin = $user->hasRole('super_admin');

        return $query->where(function (Builder $q) use ($user, $isSuperAdmin) {
            // Published branch
            $q->where(function (Builder $pub) use ($user, $isSuperAdmin) {
                $pub->where('status', TaskStatus::Published->value);
                if (! $isSuperAdmin) {
                    // Active member of the task's project whose access_scope ON THAT PROJECT allows the
                    // task's section: 'all', an unsectioned task, or a matching section.
                    $pub->whereHas('module.project.members', function (Builder $m) use ($user) {
                        $m->where('users.id', $user->id)
                          ->where('project_members.active', true)
                          ->where(fn (Builder $s) => $s
                              ->where('project_members.access_scope', AccessScope::All->value)
                              ->orWhereNull('tasks.section')
                              ->orWhereColumn('tasks.section', 'project_members.access_scope'));
                    });
                }
            });
            // Draft branch — creator only (applies to everyone incl. super_admin)
            $q->orWhere(function (Builder $draft) use ($user) {
                $draft->where('status', TaskStatus::Draft->value)
                      ->where('creator_id', $user->id);
            });
        });
    }
}

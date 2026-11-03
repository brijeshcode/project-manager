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

class Task extends Model
{
    use FreezesLocalAmounts, HasAttachments, HasComments, HasLabels;
    use LogsActivity { activityValue as protected baseActivityValue; }

    /** Changes to these are written to the activity log. */
    public array $loggedFields = ['title', 'description', 'deadline', 'priority', 'board_column_id', 'client_visible', 'estimate_hours'];

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
        'creator_id',
        'title',
        'description',
        'status',
        'is_idea',
        'priority',
        'estimate_hours',
        'scope',
        'price',
        'exchange_rate',
        'local_price',
        'client_visible',
        'testing_enabled',
        'testing_add_type',
        'testing_add_value',
        'board_column_id',
        'position',
        'deadline',
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
            'client_visible'   => 'boolean',
            'testing_enabled'  => 'boolean',
            'estimate_hours'   => 'float',
            'price'            => 'float',
            'testing_add_value' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // a new task starts in its project's first column unless told otherwise
        static::creating(function (self $t) {
            $t->board_column_id ??= Module::find($t->module_id)?->project?->columns()->value('id');
        });

        static::deleting(function (self $t) {
            $t->subtasks->each->delete();
            $t->comments()->delete();
            $t->activities()->delete();
            $t->attachments->each->delete(); // also removes the files
        });
    }

    protected function localAmounts(): array
    {
        return ['price' => ['local_price', 'major']];
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
            $field === 'priority' => ['Low', 'Medium', 'High'][(int) $v] ?? (string) $v,
            default => $this->baseActivityValue($v, $field),
        };
    }

    /** Fields a copy carries over (never comments, attachments, activity, column or position). */
    public const COPY_FIELDS = ['description', 'status', 'is_idea', 'priority', 'estimate_hours', 'scope', 'price', 'client_visible', 'testing_enabled', 'testing_add_type', 'testing_add_value', 'deadline', 'section'];

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

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
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

    /** Own effectiveHours + sum of all subtask estimate_hours. */
    public function totalEstimateHours(): float
    {
        return $this->effectiveHours() + (float) $this->subtasks()->sum('estimate_hours');
    }

    /**
     * Move this task to another column of its own project at the given position.
     * Silently ignores a column from another project (no exception, just a no-op guard).
     */
    public function moveTo(int $columnId, int $position): void
    {
        if (! $this->module->project->columns()->whereKey($columnId)->exists()) {
            return;
        }

        $this->update(['board_column_id' => $columnId, 'position' => $position]);
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

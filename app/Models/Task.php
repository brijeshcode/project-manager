<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\BoardColumn;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\FreezesLocalAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Task extends Model
{
    use FreezesLocalAmounts;

    protected $attributes = [
        'is_idea'        => false,
        'client_visible' => false,
        'testing_enabled' => false,
        'priority'       => 0,
        'estimate_hours' => 0.0,
        'board_column'   => 'Backlog',
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
        'board_column',
        'position',
        'section',
        'client_approval',
    ];

    protected function casts(): array
    {
        return [
            'status'           => TaskStatus::class,
            'scope'            => TaskScope::class,
            'is_idea'          => 'boolean',
            'client_visible'   => 'boolean',
            'testing_enabled'  => 'boolean',
            'estimate_hours'   => 'float',
            'price'            => 'float',
            'testing_add_value' => 'float',
        ];
    }

    protected function localAmounts(): array
    {
        return ['price' => ['local_price', 'major']];
    }

    protected function moneyCurrency(): ?string
    {
        return $this->module?->project?->currency;
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_user');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'label_task');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
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
     * Move this task to a different board column at the given position.
     * Silently ignores unknown column values (ponytail: no exception thrown, just a no-op guard).
     */
    public function moveTo(string $column, int $position): void
    {
        if (! BoardColumn::isValid($column)) {
            return;
        }

        $this->update(['board_column' => $column, 'position' => $position]);
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

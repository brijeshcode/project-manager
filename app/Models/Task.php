<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\BoardColumn;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\ProjectMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Task extends Model
{
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
        'client_visible',
        'testing_enabled',
        'testing_add_type',
        'testing_add_value',
        'board_column',
        'position',
        'section',
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
     * ponytail: simple two-branch WHERE; membership sub-join mirrors ProjectPolicy::isMember.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $isSuperAdmin = $user->hasRole('super_admin');

        return $query->where(function (Builder $q) use ($user, $isSuperAdmin) {
            // Published branch
            $q->where(function (Builder $pub) use ($user, $isSuperAdmin) {
                $pub->where('status', TaskStatus::Published->value);
                if (! $isSuperAdmin) {
                    // restrict to projects where user is an active member
                    $pub->whereHas('module.project.members', function (Builder $m) use ($user) {
                        $m->where('users.id', $user->id)
                          ->where('project_members.active', true);
                    });

                    // Section filter: restrict to sections allowed by the member's access_scope.
                    // If the user has different scopes on different projects, we apply the most
                    // permissive scope they hold on any of their memberships.
                    // ponytail: per-project scope enforcement would need a JOIN; this simple approach
                    // uses the narrowest single-scope a user holds, upgrade to per-project JOIN if needed.
                    $this->applyAccessScopeFilter($pub, $user);
                }
            });
            // Draft branch — creator only (applies to everyone incl. super_admin)
            $q->orWhere(function (Builder $draft) use ($user) {
                $draft->where('status', TaskStatus::Draft->value)
                      ->where('creator_id', $user->id);
            });
        });
    }

    /**
     * Applies the section visibility filter based on the user's access_scope memberships.
     * Uses the broadest scope across all active memberships:
     *   - Any 'all' scope  → no restriction
     *   - Only 'frontend'  → frontend tasks and null-sectioned tasks
     *   - Only 'backend'   → backend tasks and null-sectioned tasks
     *   - Mixed frontend+backend (but no all) → both sections + null
     */
    private function applyAccessScopeFilter(Builder $query, User $user): void
    {
        $scopes = ProjectMember::where('user_id', $user->id)
            ->where('active', true)
            ->pluck('access_scope');

        // If any membership is 'all', no section restriction needed
        if ($scopes->contains(AccessScope::All)) {
            return;
        }

        $allowedSections = $scopes
            ->map(fn (AccessScope $s) => $s->value)
            ->unique()
            ->values()
            ->all();

        // Allow matching sections + null (unsectioned tasks visible to all scopes)
        $query->where(function (Builder $s) use ($allowedSections) {
            $s->whereNull('section')
              ->orWhereIn('section', $allowedSections);
        });
    }
}

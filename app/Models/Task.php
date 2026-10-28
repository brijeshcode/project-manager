<?php

namespace App\Models;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
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

    /**
     * Scope: published tasks restricted to projects where the user is an active member;
     * drafts only to their creator regardless of membership.
     * super_admin sees all published tasks + own drafts.
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

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
            'percent' => $this->estimate_hours * (1 + $this->testing_add_value / 100),
            'fixed'   => $this->estimate_hours + $this->testing_add_value,
            default   => $this->estimate_hours,
        };
    }

    /**
     * Scope: published tasks visible to all; drafts only to their creator.
     * Enforced at the query level so Gate::before cannot override draft hiding.
     *
     * ponytail: simple two-branch WHERE; no caching — fine for per-request queries.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('status', TaskStatus::Published->value)
              ->orWhere(function (Builder $q2) use ($user) {
                  $q2->where('status', TaskStatus::Draft->value)
                     ->where('creator_id', $user->id);
              });
        });
    }
}

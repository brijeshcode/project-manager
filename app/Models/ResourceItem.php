<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ResourceItem extends Model
{
    protected $fillable = ['resource_id', 'type', 'label', 'value', 'original_name', 'created_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'resource_item_user');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Super admin: all. Others: assigned to the item AND have project access
     * (client role: projects.client_id match; else active project_members row).
     * ponytail: per-project access_scope sections ignored; they don't apply to resources.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin')) {
            return $query;
        }

        $project = $user->hasRole('client')
            ? fn (Builder $p) => $p->where('projects.client_id', $user->client_id)
            : fn (Builder $p) => $p->whereHas('members', fn (Builder $m) => $m
                ->where('users.id', $user->id)
                ->where('project_members.active', true));

        return $query
            ->whereHas('assignees', fn (Builder $a) => $a->where('users.id', $user->id))
            ->whereHas('resource.project', $project);
    }
}

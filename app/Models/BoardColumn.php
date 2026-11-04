<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardColumn extends Model
{
    protected $fillable = ['project_id', 'name', 'stage', 'position', 'wip_limit'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    /** First and last columns of a project can't be deleted (rename is fine). */
    public function isLocked(): bool
    {
        $ids = static::where('project_id', $this->project_id)->orderBy('position')->orderBy('id')->pluck('id');

        return $ids->first() === $this->id || $ids->last() === $this->id;
    }
}

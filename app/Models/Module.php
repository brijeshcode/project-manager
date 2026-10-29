<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Module extends Model
{
    protected $fillable = ['project_id', 'name', 'position'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(\App\Models\Task::class);
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

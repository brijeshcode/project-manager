<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Subtask extends Model
{
    protected $fillable = ['task_id', 'title', 'estimate_hours', 'position'];

    protected function casts(): array
    {
        return [
            'estimate_hours' => 'float',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'subtask_user');
    }

    public function checklistItems(): MorphMany
    {
        return $this->morphMany(ChecklistItem::class, 'checklistable')->orderBy('position');
    }

    /** Returns the next position integer (1-based) within a task. */
    public static function nextPosition(int $taskId): int
    {
        return (int) static::where('task_id', $taskId)->max('position') + 1;
    }

    protected static function booted(): void
    {
        static::creating(function (Subtask $subtask) {
            if (! isset($subtask->position) || $subtask->position === 0) {
                $subtask->position = static::nextPosition($subtask->task_id);
            }
        });
    }
}

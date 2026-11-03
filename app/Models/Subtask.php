<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasLabels;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Subtask extends Model
{
    use HasAttachments, HasComments, HasLabels, LogsActivity;

    public array $loggedFields = ['title', 'description', 'estimate_hours'];

    protected $fillable = ['task_id', 'title', 'description', 'estimate_hours', 'position'];

    public function accessProject(): Project
    {
        return $this->task->module->project;
    }

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

    public function timeEntries(): MorphMany
    {
        return $this->morphMany(TimeEntry::class, 'timeable');
    }

    /** Returns the next position integer (1-based) within a task. */
    public static function nextPosition(int $taskId): int
    {
        return (int) static::where('task_id', $taskId)->max('position') + 1;
    }

    protected static function booted(): void
    {
        static::deleting(function (Subtask $s) {
            $s->comments()->delete();
            $s->activities()->delete();
            $s->attachments->each->delete();
        });

        static::creating(function (Subtask $subtask) {
            if (! isset($subtask->position) || $subtask->position === 0) {
                $subtask->position = static::nextPosition($subtask->task_id);
            }
        });
    }
}

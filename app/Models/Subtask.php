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
use Illuminate\Support\Facades\DB;

class Subtask extends Model
{
    use HasAttachments, HasComments, HasLabels, LogsActivity;

    public array $loggedFields = ['title', 'description', 'estimate_hours', 'is_done'];

    protected $fillable = ['task_id', 'title', 'description', 'estimate_hours', 'is_done', 'position'];

    public function accessProject(): Project
    {
        return $this->task->module->project;
    }

    protected function casts(): array
    {
        return [
            'estimate_hours' => 'float',
            'is_done' => 'boolean',
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

    /**
     * Promote to a task of the same module (first board column, in scope). Assignees, labels, comments,
     * attachments (rows re-pointed, files untouched), checklist items and time entries move over.
     */
    public function convertToTask(User $by, ?int $typeId = null): Task
    {
        return DB::transaction(function () use ($by, $typeId) {
            $parent = $this->task;
            $task = Task::create([
                'module_id' => $parent->module_id, 'creator_id' => $by->id, 'title' => $this->title,
                'description' => $this->description, 'estimate_hours' => $this->estimate_hours,
                'scope' => 'in', 'status' => 'published', 'task_type_id' => $typeId, // null = the default type
            ]);
            $task->assignees()->sync($this->assignees()->pluck('users.id')->all());
            $task->labels()->sync($this->labels()->pluck('labels.id')->all());
            foreach (['comments' => 'commentable', 'attachments' => 'attachable', 'checklistItems' => 'checklistable', 'timeEntries' => 'timeable'] as $rel => $morph) {
                $this->{$rel}()->reorder()->update(["{$morph}_type" => $task->getMorphClass(), "{$morph}_id" => $task->id]);
            }
            $task->logActivity('converted', null, $parent->title);
            $this->delete();

            return $task;
        });
    }

    /** Returns the next position integer (1-based) within a task. */
    public static function nextPosition(int $taskId): int
    {
        return (int) static::where('task_id', $taskId)->max('position') + 1;
    }

    /** Persist a drag-reorder of a task's subtasks (ids of other tasks are ignored). */
    public static function reorder(array $orderedIds, int $taskId): void
    {
        DB::transaction(function () use ($orderedIds, $taskId) {
            foreach (array_values(array_map('intval', $orderedIds)) as $i => $id) {
                static::where('id', $id)->where('task_id', $taskId)->update(['position' => $i + 1]);
            }
        });
    }

    protected static function booted(): void
    {
        static::deleting(function (Subtask $s) {
            $s->comments()->delete();
            $s->activities()->delete();
            $s->attachments()->get()->each->delete(); // fresh query: moved rows are no longer ours
        });

        static::creating(function (Subtask $subtask) {
            if (! isset($subtask->position) || $subtask->position === 0) {
                $subtask->position = static::nextPosition($subtask->task_id);
            }
        });
    }
}

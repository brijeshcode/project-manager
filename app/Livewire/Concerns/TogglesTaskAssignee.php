<?php

namespace App\Livewire\Concerns;

use App\Models\Task;
use Illuminate\Support\Collection;

/** Assign/unassign one person on a project task straight from a list or board (needs a `$project` property). */
trait TogglesTaskAssignee
{
    /** Active project members: who can be assigned. */
    protected function assignable(): Collection
    {
        return $this->project->members()->wherePivot('active', true)->orderBy('name')->get();
    }

    /** Editors only. A task outside this project, a draft the user cannot see, or a non-member are silently ignored. Logged like the task page. */
    public function toggleTaskAssignee(int $taskId, int $userId): void
    {
        $this->authorize('update', $this->project);

        $task = Task::visibleTo(auth()->user())->whereIn('module_id', $this->project->modules()->select('id'))->find($taskId);
        if (! $task) {
            return;
        }
        $current = $task->assignees()->pluck('users.id')->all();
        if (in_array($userId, $current, true)) {
            $ids = array_values(array_diff($current, [$userId]));
        } elseif ($this->assignable()->contains('id', $userId)) {
            $ids = [...$current, $userId];
        } else {
            return;
        }
        $task->syncAssignees($ids);
    }
}

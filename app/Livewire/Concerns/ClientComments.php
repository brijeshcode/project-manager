<?php

namespace App\Livewire\Concerns;

use App\Enums\TaskStatus;
use App\Models\Module;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** A client's comments on the tasks and modules of $this->project they may see. */
trait ClientComments
{
    /** Keyed by task id / module id / parent comment id. */
    public array $taskBody = [];

    public array $moduleBody = [];

    public array $replyBody = [];

    /** Modules shared with the client; tasks of any other module are hidden too. */
    private function visibleModuleIds(): Collection
    {
        return $this->project->modules()->where('client_visible', true)->pluck('id');
    }

    /** Published, client-visible, approved tasks of the shared modules. */
    private function clientTasks(): Builder
    {
        return Task::whereIn('module_id', $this->visibleModuleIds())
            ->where('status', TaskStatus::Published->value)
            ->where('client_visible', true)
            ->approved();
    }

    /** Task the client may see, or 403. */
    private function guardTask(int $taskId): Task
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        $task = $this->clientTasks()->find($taskId);
        abort_unless($task, 403);

        return $task;
    }

    /** Module of this project that is shared with the client, or 403. */
    private function guardModule(int $moduleId): Module
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        $module = $this->project->modules()->where('client_visible', true)->find($moduleId);
        abort_unless($module, 403);

        return $module;
    }

    public function addComment(int $taskId): void
    {
        $this->validate(['taskBody.'.$taskId => 'required|string|max:5000']);
        $this->guardTask($taskId)->addComment(auth()->user(), $this->taskBody[$taskId]);
        unset($this->taskBody[$taskId]);
    }

    public function addModuleComment(int $moduleId): void
    {
        $this->validate(['moduleBody.'.$moduleId => 'required|string|max:5000']);
        $this->guardModule($moduleId)->addComment(auth()->user(), $this->moduleBody[$moduleId]);
        unset($this->moduleBody[$moduleId]);
    }

    /** Reply inside a thread the client can see; $kind is 'task' or 'module'. */
    public function reply(string $kind, int $subjectId, int $parentId): void
    {
        $this->validate(['replyBody.'.$parentId => 'required|string|max:5000']);
        $subject = $kind === 'module' ? $this->guardModule($subjectId) : $this->guardTask($subjectId);
        $subject->comments()->visibleToClient(auth()->user())->findOrFail($parentId);
        $subject->addComment(auth()->user(), $this->replyBody[$parentId], $parentId);
        unset($this->replyBody[$parentId]);
    }
}

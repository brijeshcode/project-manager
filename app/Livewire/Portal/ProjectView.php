<?php

namespace App\Livewire\Portal;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProjectView extends Component
{
    public Project $project;

    /** New task title (create form). */
    public string $newTitle = '';

    /** Comment body for adding a comment. */
    public string $commentBody = '';

    public function mount(Project $project): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);

        // Cross-client fence: project must belong to this user's client.
        abort_unless(
            (int) $project->client_id === (int) auth()->user()->client_id,
            403
        );

        $this->project = $project;
    }

    /**
     * Only published, client_visible tasks for this project.
     * Price and cost fields are never included in the select.
     * ponytail: explicit column list guarantees no money field leaks.
     */
    #[Computed]
    public function tasks(): Collection
    {
        $moduleIds = $this->project->modules()->pluck('id');

        return Task::whereIn('module_id', $moduleIds)
            ->where('status', TaskStatus::Published->value)
            ->where('client_visible', true)
            ->orderBy('board_column')
            ->orderBy('position')
            ->get([
                'id', 'module_id', 'creator_id', 'title', 'description',
                'status', 'priority', 'board_column', 'position',
                'client_visible', 'client_approval', 'scope', 'section',
                // Deliberately omits: price, estimate_hours, testing_*, cost fields
            ]);
    }

    /**
     * Client creates a task: always client_visible=true, published, scope=in.
     * Decision: lands as 'published' directly — the client is making a request
     * that must be visible to the team immediately. No draft-pending step; a
     * super_admin can reclassify or delete it. (Documented simple default.)
     */
    public function createTask(): void
    {
        $this->validate(['newTitle' => 'required|string|max:255']);

        $module = $this->project->modules()->first();
        abort_unless($module, 422, 'Project has no modules.');

        Task::create([
            'module_id'      => $module->id,
            'creator_id'     => auth()->id(),
            'title'          => $this->newTitle,
            'status'         => TaskStatus::Published,
            'scope'          => TaskScope::In,
            'client_visible' => true,
            'priority'       => 0,
            'estimate_hours' => 0.0,
            'board_column'   => 'Backlog',
            'position'       => Task::whereIn('module_id', $this->project->modules()->pluck('id'))
                ->max('position') + 1,
        ]);

        $this->newTitle = '';
        unset($this->tasks);
    }

    /**
     * Add a comment to a client_visible task in this project.
     */
    public function addComment(int $taskId): void
    {
        $this->validate(['commentBody' => 'required|string|max:5000']);

        $task = $this->guardTask($taskId);

        $task->comments()->create([
            'author_id' => auth()->id(),
            'body'      => $this->commentBody,
        ]);

        $this->commentBody = '';
    }

    /**
     * Change the priority of a client_visible task in this project.
     */
    public function setPriority(int $taskId, int $priority): void
    {
        $task = $this->guardTask($taskId);
        $task->update(['priority' => max(0, $priority)]);
        unset($this->tasks);
    }

    /**
     * Sync labels on a client_visible task in this project.
     * @param list<int> $labelIds
     */
    public function setLabels(int $taskId, array $labelIds): void
    {
        $task = $this->guardTask($taskId);
        $task->labels()->sync($labelIds);
        unset($this->tasks);
    }

    /**
     * Approve a task — only allowed when the task is in the Done column.
     */
    public function approveTask(int $taskId): void
    {
        $task = $this->guardTask($taskId);

        if ($task->board_column !== 'Done') {
            return; // silently ignore — task not complete
        }

        $task->update(['client_approval' => 'approved']);
        unset($this->tasks);
    }

    /**
     * Scope guard: task must be published, client_visible, and in this project.
     * Returns the task or aborts 403.
     */
    private function guardTask(int $taskId): Task
    {
        $moduleIds = $this->project->modules()->pluck('id');

        $task = Task::whereIn('module_id', $moduleIds)
            ->where('status', TaskStatus::Published->value)
            ->where('client_visible', true)
            ->find($taskId);

        abort_unless($task, 403);

        return $task;
    }

    public function render()
    {
        return view('livewire.portal.project-view', [
            'tasks' => $this->tasks,
        ])->layout('layouts.app');
    }
}

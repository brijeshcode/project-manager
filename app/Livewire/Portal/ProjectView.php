<?php

namespace App\Livewire\Portal;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use App\Livewire\Concerns\HandlesMarkdownUploads;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class ProjectView extends Component
{
    use HandlesMarkdownUploads; // only for the editor's Preview; clients cannot upload

    protected function uploadTarget(): Model
    {
        abort(403);
    }

    public Project $project;

    /** New task title (create form). */
    public string $newTitle = '';

    /** Comment body for adding a comment. */
    public string $commentBody = '';

    /** Keyed by module id / parent comment id. */
    public array $moduleBody = [];

    public array $replyBody = [];

    public function mount(Project $project): void
    {
        $this->authorize('viewAsClient', $project);

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
            ->with('column')
            ->orderBy('board_column_id')
            ->orderBy('position')
            ->get([
                'id', 'module_id', 'creator_id', 'title', 'description',
                'status', 'priority', 'board_column_id', 'position',
                'client_visible', 'client_approval', 'scope', 'section', 'deadline',
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

        $task->addComment(auth()->user(), $this->commentBody);

        $this->commentBody = '';
    }

    /** Comment on a client-visible module of this project. */
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

    /** Module of this project that is shared with the client, or 403. */
    private function guardModule(int $moduleId): Module
    {
        $module = $this->project->modules()->where('client_visible', true)->find($moduleId);
        abort_unless($module, 403);

        return $module;
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

        abort_unless($task->column?->stage === 'done', 422, 'Only completed tasks can be approved.');

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
        $client = auth()->user();

        return view('livewire.portal.project-view', [
            'tasks' => $this->tasks,
            'modules' => $this->project->modules()->where('client_visible', true)->orderBy('position')->get(),
            'threads' => fn ($subject) => $subject->commentThreads($client),
        ])->layout('layouts.app');
    }
}

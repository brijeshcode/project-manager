<?php

namespace App\Livewire\Portal;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
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

    public const TABS = ['details' => 'Details', 'modules' => 'Modules', 'sprints' => 'Sprints', 'board' => 'Board', 'budget' => 'Budget', 'planned' => 'Planned', 'resources' => 'Resources'];

    public Project $project;

    #[Url]
    public string $tab = 'modules';

    /** New task title (create form). */
    public string $newTitle = '';

    /** Keyed by task id / module id / parent comment id. */
    public array $taskBody = [];

    public array $moduleBody = [];

    public array $replyBody = [];

    public function mount(Project $project): void
    {
        $this->authorize('viewAsClient', $project);

        $this->project = $project;
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'modules';
    }

    /** Modules shared with the client; tasks of any other module are hidden too. */
    private function visibleModuleIds(): Collection
    {
        return $this->project->modules()->where('client_visible', true)->pluck('id');
    }

    /**
     * Only published, client_visible tasks for this project.
     * Only the task's own budget amount is selected; rates, hours and cost fields never are.
     * ponytail: explicit column list guarantees no other money field leaks.
     */
    #[Computed]
    public function tasks(): Collection
    {
        return Task::whereIn('module_id', $this->visibleModuleIds())
            ->where('status', TaskStatus::Published->value)
            ->where('client_visible', true)
            ->with('column')
            ->orderBy('board_column_id')
            ->orderBy('position')
            ->get([
                'id', 'project_id', 'number', 'module_id', 'creator_id', 'title', 'description',
                'status', 'priority', 'board_column_id', 'position',
                'client_visible', 'client_approval', 'scope', 'section', 'deadline', 'sprint_id', 'budget_amount',
                // Deliberately omits: estimate_hours, hourly_rate, billing_mode, testing_*, cost fields
            ])->each->setRelation('project', $this->project);
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

        $module = $this->project->modules()->where('client_visible', true)->first();
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
        $this->validate(['taskBody.'.$taskId => 'required|string|max:5000']);

        $task = $this->guardTask($taskId);

        $task->addComment(auth()->user(), $this->taskBody[$taskId]);

        unset($this->taskBody[$taskId]);
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
        $task = Task::whereIn('module_id', $this->visibleModuleIds())
            ->where('status', TaskStatus::Published->value)
            ->where('client_visible', true)
            ->find($taskId);

        abort_unless($task, 403);

        return $task;
    }

    public function render()
    {
        $client = auth()->user();

        $tasks = $this->tasks;

        return view('livewire.portal.project-view', [
            'tasks' => $tasks,
            'modules' => $this->project->modules()->where('client_visible', true)->orderBy('position')
                ->with(['tasks' => fn ($q) => $q->where('status', TaskStatus::Published->value)->where('client_visible', true)->orderBy('position')->orderBy('id'), 'tasks.project', 'tasks.subtasks', 'tasks.labels', 'tasks.type', 'tasks.column', 'labels', 'project.client'])->get(),
            'progress' => app(\App\Services\ProgressService::class),
            // sprints and columns with no visible task are left out
            'planned' => $tasks->filter(fn ($t) => $t->column?->stage === 'not_started')->values(),
            'threads' => fn ($subject) => $subject->commentThreads($client),
        ])->layout('layouts.app');
    }
}

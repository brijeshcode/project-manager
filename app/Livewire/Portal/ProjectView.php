<?php

namespace App\Livewire\Portal;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ClientComments;
use App\Livewire\Concerns\ClientRequests;
use App\Livewire\Concerns\FiltersModules;
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
    use ClientComments, ClientRequests, FiltersModules;
    use HandlesMarkdownUploads; // only for the editor's Preview; clients cannot upload

    protected function uploadTarget(): Model
    {
        abort(403);
    }

    public const TABS = ['details' => 'Details', 'modules' => 'Modules', 'sprints' => 'Sprints', 'board' => 'Board', 'budget' => 'Budget', 'planned' => 'Planned', 'resources' => 'Resources'];

    public Project $project;

    #[Url]
    public string $tab = 'modules';

    public function mount(Project $project): void
    {
        $this->authorize('viewAsClient', $project);

        $this->project = $project;
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'modules';
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
            ->approved()
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

    public function render()
    {
        $client = auth()->user();

        $tasks = $this->tasks;
        $modules = $this->project->modules()->where('client_visible', true)->orderBy('position')
            ->with(['tasks' => fn ($q) => $q->where('status', TaskStatus::Published->value)->where('client_visible', true)->orderBy('position')->orderBy('id'), 'tasks.project', 'tasks.subtasks', 'tasks.labels', 'tasks.type', 'tasks.column', 'labels', 'project.client'])->get();

        return view('livewire.portal.project-view', [
            'tasks' => $tasks,
            'modules' => $modules,
            'progress' => app(\App\Services\ProgressService::class),
            // sprints and columns with no visible task are left out
            'planned' => $tasks->filter(fn ($t) => $t->column?->stage === 'not_started')->values(),
            'threads' => fn ($subject) => $subject->commentThreads($client),
            'budgetModules' => $this->tab === 'budget' ? $this->visibleModules(fn ($q) => $q->budgeted()) : null,
            'countModules' => $countModules = $this->tab === 'details' ? $this->visibleModules(fn ($q) => $q->counted()) : null,
            'countTasks' => $countModules?->flatMap->tasks,
        ] + $this->requestOptions() + $this->moduleRows($modules))->layout('layouts.app');
    }

    /** Client-visible modules with their published client-visible tasks; only safe columns (no hours, rates, costs). */
    private function visibleModules(\Closure $scope): Collection
    {
        $modules = $this->project->modules()->where('client_visible', true)->approved()
            ->with(['tasks' => fn ($q) => $scope($q)->where('client_visible', true)->orderBy('position')->orderBy('id')
                ->select(['id', 'project_id', 'number', 'module_id', 'title', 'status', 'board_column_id', 'position', 'budget_amount']), 'tasks.column'])
            ->get();
        $modules->flatMap->tasks->each->setRelation('project', $this->project);

        return $modules;
    }
}

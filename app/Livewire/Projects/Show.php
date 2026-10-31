<?php

namespace App\Livewire\Projects;

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\ChecklistItem;
use App\Models\Module;
use App\Models\Project;
use App\Support\Money;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ProgressService;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    public bool $canManage = false;

    public string $newModuleName = '';

    public string $newModuleDescription = '';

    /** Keyed by module id. */
    public array $newTaskTitle = [];

    /** Keyed by task id. */
    public array $newSubtaskTitle = [];

    /** Keyed by task id. */
    public array $newChecklistTitle = [];

    /** Editable descriptions, keyed by id (pre-filled at mount). */
    public array $moduleDescriptions = [];

    public array $taskDescriptions = [];

    /** Budget inputs per module id: hours, rate, amount (major units), deadline, reason. Owner only. */
    public array $budget = [];

    public bool $canMoney = false;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->canManage = auth()->user()->can('update', $project);

        $this->canMoney = auth()->user()->can('manage-money');

        foreach ($project->modules()->with('tasks')->get() as $module) {
            if ($this->canMoney) {
                $this->fillBudget($module);
            }
            $this->moduleDescriptions[$module->id] = $module->description ?? '';
            foreach ($module->tasks as $task) {
                $this->taskDescriptions[$task->id] = $task->description ?? '';
            }
        }
    }

    public function addModule(): void
    {
        $this->authorize('update', $this->project);
        $this->validate(['newModuleName' => 'required|string|max:255']);

        $module = Module::create([
            'project_id'  => $this->project->id,
            'name'        => trim($this->newModuleName),
            'description' => trim($this->newModuleDescription) ?: null,
            'position'    => Module::nextPosition($this->project->id),
        ]);

        $this->moduleDescriptions[$module->id] = $module->description ?? '';
        $this->newModuleName = '';
        $this->newModuleDescription = '';
    }

    protected function fillBudget(Module $m): void
    {
        $this->budget[$m->id] = [
            'hours'    => $m->budget_hours === null ? '' : (string) $m->budget_hours,
            'rate'     => Money::toInput($m->hourly_rate_cents),
            'amount'   => Money::toInput($m->budget_amount_cents),
            'deadline' => $m->deadline?->toDateString() ?? '',
            'reason'   => '',
        ];
    }

    public function saveBudget(int $moduleId): void
    {
        $this->authorize('manage-money');

        $module = $this->project->modules()->findOrFail($moduleId);
        $this->validate([
            "budget.$moduleId.hours"    => 'nullable|numeric|min:0|max:999999',
            "budget.$moduleId.rate"     => ['nullable', 'regex:/^[\d,]{1,14}(\.\d{1,3})?$/'],
            "budget.$moduleId.amount"   => ['nullable', 'regex:/^[\d,]{1,14}(\.\d{1,3})?$/'],
            "budget.$moduleId.deadline" => 'nullable|date',
            "budget.$moduleId.reason"   => 'nullable|string|max:1000',
        ]);

        $b = $this->budget[$moduleId];
        $cents = fn ($v) => ($v ?? '') === '' ? null : Money::toCents(str_replace(',', '', $v));
        try {
            $module->updateBudget([
                'budget_hours'        => ($b['hours'] ?? '') === '' ? null : $b['hours'],
                'hourly_rate_cents'   => $cents($b['rate'] ?? null),
                'budget_amount_cents' => $cents($b['amount'] ?? null),
                'deadline'            => ($b['deadline'] ?? '') ?: null,
            ], auth()->user(), (string) ($b['reason'] ?? ''));
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError("budget.$moduleId.reason", $e->errors()['reason'][0] ?? 'Reason required.');

            return;
        }

        $this->fillBudget($module->fresh());
    }

    public function saveModuleDescription(int $moduleId): void
    {
        $this->authorize('update', $this->project);

        $module = $this->project->modules()->findOrFail($moduleId);
        $module->update(['description' => trim($this->moduleDescriptions[$moduleId] ?? '') ?: null]);
    }

    public function saveTaskDescription(int $taskId): void
    {
        $this->authorize('update', $this->project);

        $task = $this->taskInProject($taskId);
        $task->update(['description' => trim($this->taskDescriptions[$taskId] ?? '') ?: null]);
    }

    public function addTask(int $moduleId): void
    {
        $this->authorize('update', $this->project);

        $title = trim($this->newTaskTitle[$moduleId] ?? '');
        if ($title === '') {
            return;
        }

        // Guard: the module must belong to this project.
        $module = $this->project->modules()->findOrFail($moduleId);

        $task = Task::create([
            'module_id'    => $module->id,
            'creator_id'   => auth()->id(),
            'title'        => $title,
            'status'       => TaskStatus::Published,
            'scope'        => TaskScope::In,
            'board_column' => 'Backlog',
            'position'     => (int) Task::where('module_id', $module->id)->max('position') + 1,
        ]);

        $this->taskDescriptions[$task->id] = '';
        $this->newTaskTitle[$moduleId] = '';
    }

    public function addSubtask(int $taskId): void
    {
        $this->authorize('update', $this->project);

        $title = trim($this->newSubtaskTitle[$taskId] ?? '');
        if ($title === '') {
            return;
        }

        Subtask::create([
            'task_id' => $this->taskInProject($taskId)->id,
            'title'   => $title,
        ]);

        $this->newSubtaskTitle[$taskId] = '';
    }

    public function addChecklistItem(int $taskId): void
    {
        $this->authorize('update', $this->project);

        $title = trim($this->newChecklistTitle[$taskId] ?? '');
        if ($title === '') {
            return;
        }

        $task = $this->taskInProject($taskId);

        ChecklistItem::create([
            'checklistable_type' => Task::class,
            'checklistable_id'   => $task->id,
            'title'              => $title,
            'is_done'            => false,
            'position'           => (int) $task->checklistItems()->max('position') + 1,
        ]);

        $this->newChecklistTitle[$taskId] = '';
    }

    public function toggleChecklistItem(int $itemId): void
    {
        $this->authorize('update', $this->project);

        $item = ChecklistItem::where('id', $itemId)
            ->where('checklistable_type', Task::class)
            ->whereHasMorph('checklistable', [Task::class], fn ($q) => $q
                ->whereHas('module', fn ($m) => $m->where('project_id', $this->project->id)))
            ->firstOrFail();

        $item->update(['is_done' => ! $item->is_done]);
    }

    /** Resolve a task, asserting it belongs to this project. */
    protected function taskInProject(int $taskId): Task
    {
        return Task::where('id', $taskId)
            ->whereHas('module', fn ($q) => $q->where('project_id', $this->project->id))
            ->firstOrFail();
    }

    public function render()
    {
        $modules = $this->project->modules()
            ->with([
                'tasks' => fn ($q) => $q->visibleTo(auth()->user())->orderBy('position'),
                'tasks.subtasks',
                'tasks.checklistItems',
            ])
            ->get();

        return view('livewire.projects.show', [
            'project'  => $this->project->load('client', 'members'),
            'modules'  => $modules,
            'progress' => app(ProgressService::class),
        ]);
    }
}

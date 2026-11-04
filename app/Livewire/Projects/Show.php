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
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use App\Livewire\Concerns\HandlesMarkdownUploads;
use App\Livewire\Concerns\TogglesTaskAssignee;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class Show extends Component
{
    use HandlesMarkdownUploads;
    use TogglesTaskAssignee;

    // ponytail: inline editors here are text-only; attach files on the module/task page
    protected function uploadTarget(): Model
    {
        abort(403);
    }

    public Project $project;

    #[Locked]
    public bool $canManage = false;

    #[Url]
    public string $tab = 'details';

    /** Module list filter: all | sprint | backlog | completed. */
    #[Url(as: 'filter')]
    public string $filter = 'all';

    /** Module list user filter: a user id, or '' for everyone. */
    #[Url(as: 'user')]
    public string $assignedTo = '';

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

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->canManage = auth()->user()->can('update', $project);

        foreach ($project->modules()->with('tasks')->get() as $module) {
            if (auth()->user()->can('manage-money')) {
                $this->fillBudget($module);
            }
            $this->moduleDescriptions[$module->id] = $module->description ?? '';
            foreach ($module->tasks as $task) {
                $this->taskDescriptions[$task->id] = $task->description ?? '';
            }
        }
    }

    protected function fillBudget(Module $m): void
    {
        $this->budget[$m->id] = [
            'hours'    => $m->budget_hours === null ? '' : (string) $m->budget_hours,
            'rate'     => $m->hourly_rate ?? '',
            'amount'   => $m->budget_amount ?? '',
            'deadline' => $m->deadline?->toDateString() ?? '',
            'reason'   => '',
        ];
    }

    public string $newDeadline = '';

    public string $deadlineReason = '';

    public function changeDeadline(): void
    {
        $this->authorize('update', $this->project);
        $this->validate(['newDeadline' => 'required|date', 'deadlineReason' => 'nullable|string|max:1000']);

        try {
            $this->project->setDeadline($this->newDeadline, auth()->user(), $this->deadlineReason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('deadlineReason', $e->errors()['reason'][0]);

            return;
        }
        $this->reset('newDeadline', 'deadlineReason');
    }

    public function saveBudget(int $moduleId): void
    {
        $this->authorize('manage-money');

        $module = $this->project->modules()->findOrFail($moduleId);
        // commas are stripped, so bound the digits that remain (max 9,999,999.99)
        $money = fn ($a, $v, $fail) => preg_match('/^\d{1,7}(\.\d{1,2})?$/', str_replace(',', '', (string) $v)) || $fail('Too large.');
        $this->validate([
            "budget.$moduleId.hours"    => ['bail', 'nullable', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
            "budget.$moduleId.rate"     => ['bail', 'nullable', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
            "budget.$moduleId.amount"   => ['bail', 'nullable', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
            "budget.$moduleId.deadline" => 'nullable|date',
            "budget.$moduleId.reason"   => 'nullable|string|max:1000',
        ]);

        $b = $this->budget[$moduleId];
        $amt = fn ($v) => ($v ?? '') === '' ? null : Money::toInput(Money::toCents(str_replace(',', '', $v)));
        try {
            $module->updateBudget([
                'budget_hours'        => ($b['hours'] ?? '') === '' ? null : $b['hours'],
                'hourly_rate'   => $amt($b['rate'] ?? null),
                'budget_amount' => $amt($b['amount'] ?? null),
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

    /** Drag-reorder of a module's tasks (the module's one task order). */
    public function reorderTasks(int $moduleId, array $ids): void
    {
        $this->authorize('update', $this->project);
        Task::reorder($this->project->modules()->findOrFail($moduleId)->id, $ids);
    }

    /** Drag-reorder of module rows: the submitted modules swap into the slots they occupied, so a filtered view never disturbs the rest. */
    public function reorderModules(array $ids): void
    {
        $this->authorize('update', $this->project);

        $all = $this->project->modules()->orderBy('id')->pluck('id'); // position ties broken by id
        $ids = collect($ids)->map(fn ($i) => (int) $i)->unique()->intersect($all)->values();
        $queue = $ids->all();
        $order = [];
        foreach ($all as $id) {
            $order[] = $ids->contains($id) ? array_shift($queue) : $id;
        }
        Module::reorder($order, $this->project->id);
    }

    public function setModulePriority(int $moduleId, int $priority): void
    {
        $this->authorize('update', $this->project);
        abort_unless(in_array($priority, [0, 1, 2], true), 422);
        $this->project->modules()->findOrFail($moduleId)->update(['priority' => $priority]);
    }

    /** Add/remove one assignee straight from the list (same logging as the module page sidebar). */
    public function toggleModuleAssignee(int $moduleId, int $userId): void
    {
        $this->authorize('update', $this->project);

        $module = $this->project->modules()->findOrFail($moduleId);
        $current = $module->assignees()->pluck('users.id')->all();
        if (in_array($userId, $current, true)) {
            $ids = array_values(array_diff($current, [$userId]));
        } else {
            abort_unless($this->candidates()->contains('id', $userId), 403);
            $ids = [...$current, $userId];
        }
        $module->syncAssignees($ids);
    }

    /** Active project members: who can be assigned. */
    protected function candidates()
    {
        return $this->project->members()->wherePivot('active', true)->orderBy('name')->get();
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
                'tasks' => fn ($q) => $q->visibleTo(auth()->user())->orderBy('position')->orderBy('id'),
                'tasks.subtasks',
                'tasks.assignees',
                'tasks.labels',
                'tasks.type',
                'tasks.column',
                'assignees',
                'labels',
                'tasks.checklistItems',
                'project.client',
            ])
            ->get();

        // Sprint = modules with at least one task in the ACTIVE sprint; Backlog = open modules without one.
        $activeSprint = $this->project->sprints()->active()->first();
        $sprintModules = $activeSprint ? Task::counted()->where('sprint_id', $activeSprint->id)->pluck('module_id')->unique() : collect();
        $userId = (int) $this->assignedTo;
        $filter = in_array($this->filter, ['all', 'sprint', 'backlog', 'completed'], true) ? $this->filter : 'all';
        $rows = $modules->map(fn ($m) => ['m' => $m, 'status' => $m->status()])
            ->filter(fn ($r) => ! $userId || $r['m']->assignees->contains('id', $userId) || $r['m']->tasks->contains(fn ($t) => $t->assignees->contains('id', $userId)))
            ->values();
        $done = $rows->filter(fn ($r) => $r['status'] === 'completed')->values();
        $open = $rows->reject(fn ($r) => $r['status'] === 'completed')->values();
        $main = match ($filter) {
            'completed' => $done,
            'sprint' => $rows->filter(fn ($r) => $sprintModules->contains($r['m']->id))->values(),
            'backlog' => $open->reject(fn ($r) => $sprintModules->contains($r['m']->id))->values(),
            default => $open,
        };

        $tabs = ['details' => 'Details', 'modules' => 'Modules', 'sprint' => 'Sprint', 'resources' => 'Resources', 'board' => 'Board']
            + (auth()->user()->can('manage-money') ? ['economics' => 'Economics'] : []);

        return view('livewire.projects.show', [
            'tabs'     => $tabs,
            'active'   => isset($tabs[$this->tab]) ? $this->tab : 'details',
            'project'  => $this->project->load('client', 'members'),
            'modules'  => $modules,
            'mainRows' => $main,
            'doneRows' => $filter === 'all' ? $done : collect(),
            'filter'   => $filter,
            'activeSprint' => $activeSprint,
            'candidates' => $this->candidates(),
            'userOptions' => $this->project->members()->orderBy('name')->get(),
            'progress' => app(ProgressService::class),
        ]);
    }
}

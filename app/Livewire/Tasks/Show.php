<?php

namespace App\Livewire\Tasks;

use App\Exceptions\OverBudget;
use App\Livewire\Concerns\HandlesBudgetBlock;
use App\Livewire\Concerns\ItemPage;
use App\Services\ModuleBudget;
use App\Support\Money;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\ProgressService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    use HandlesBudgetBlock, ItemPage;

    public Task $task;

    #[Locked]
    public bool $canEdit = false;

    #[Locked]
    public bool $canCost = false;

    /** Amounts are for users who manage money; everyone else sees hours only. */
    #[Locked]
    public bool $canMoney = false;

    public string $deadline = '';

    public string $deadlineReason = '';

    public int $priority = 0;

    public bool $clientVisible = false;

    public string $status = 'published';

    public bool $isIdea = false;

    public string $scope = 'in';

    public string $estimate = '0';

    public string $budget_amount = '';

    public ?int $typeId = null;

    public string $newSubtask = '';

    public string $startedAt = '';

    public string $completedAt = '';

    /** Shown in the board slide-over: no breadcrumb, and changes are announced so the board card follows. */
    #[Locked]
    public bool $embedded = false;

    private bool $hydrated = false;

    public function hydrate(): void
    {
        $this->hydrated = true;
    }

    public function mount(Task $task, bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $user = auth()->user();
        // same visibility as lists/board: drafts of others and out-of-section tasks are 404
        abort_unless(Task::visibleTo($user)->whereKey($task->id)->exists(), 404);
        $this->authorize('view', $task->module->project);
        $this->authorize('view', $task);
        $this->task = $task;
        $this->canEdit = $user->can('update', $task);
        $this->canCost = $user->can('updateCostTime', $task);
        $this->canMoney = $user->can('manage-money');
    }

    protected function item(): Model
    {
        return $this->task;
    }

    protected function itemProject(): Project
    {
        return $this->task->module->project;
    }

    protected function authorizeEdit(): void
    {
        $this->authorize('update', $this->task);
    }

    protected function fillPanel(string $panel): void
    {
        $t = $this->task;
        $this->deadline = $t->deadline?->toDateString() ?? '';
        $this->deadlineReason = '';
        $this->priority = $t->priority;
        $this->typeId = $t->task_type_id;
        $this->clientVisible = $t->client_visible;
        $this->status = $t->status->value;
        $this->isIdea = $t->is_idea;
        $this->scope = $t->scope->value;
        $this->estimate = (string) $t->estimate_hours;
        $this->budget_amount = $t->budget_amount === null ? '' : (string) $t->budget_amount;
        $this->startedAt = $t->started_at?->toDateString() ?? '';
        $this->completedAt = $t->completed_at?->toDateString() ?? '';
    }

    protected function saveExtra(string $panel): void
    {
        $t = $this->task;
        match ($panel) {
            'deadline' => $this->saveDeadline($t),
            'type' => $t->update(['task_type_id' => $this->validate(['typeId' => ['required', Rule::exists('task_types', 'id')]])['typeId']]),
            'priority' => $t->update($this->validate(['priority' => 'integer|in:0,1,2'])),
            'visibility' => $this->saveVisibility($t),
            'estimate' => $this->saveEstimate($t),
            'dates' => $this->saveDates($t),
            default => null,
        };
    }

    private function saveVisibility(Task $t): void
    {
        $this->validate(['clientVisible' => 'boolean', 'status' => 'required|in:draft,published', 'isIdea' => 'boolean']);
        $t->update(['client_visible' => $this->clientVisible, 'status' => $this->status, 'is_idea' => $this->isIdea]);
    }

    private function saveDeadline(Task $t): void
    {
        $this->validate(['deadline' => 'nullable|date', 'deadlineReason' => 'nullable|string|max:1000']);
        $t->activityReason = $this->deadlineReason;
        $t->update(['deadline' => $this->deadline ?: null]);
    }

    /** Hand-edited dates win over the automatic ones (see Task::syncDatesWithColumn); each change is logged. */
    private function saveDates(Task $t): void
    {
        $this->validate(['startedAt' => 'nullable|date', 'completedAt' => 'nullable|date|after_or_equal:startedAt']);
        $t->update(['started_at' => $this->startedAt ?: null, 'completed_at' => $this->completedAt ?: null]);
    }

    private function saveEstimate(Task $t): void
    {
        abort_unless($this->canCost, 403);
        $this->validate(['scope' => 'required|in:in,out', 'estimate' => 'numeric|min:0|max:99999', 'budget_amount' => 'nullable|numeric|min:0|max:9999999.99']);
        $data = ['scope' => $this->scope, 'estimate_hours' => (float) $this->estimate];
        if ($this->canMoney) {
            $data['budget_amount'] = $this->budget_amount === '' ? null : (float) $this->budget_amount;
        }
        // what the task would use of its module's budget after this save
        $probe = $t->replicate()->forceFill($data)->setRelation('subtasks', $t->subtasks);
        if ($probe->countsTowardBudget()) {
            $mb = new ModuleBudget($t->module);
            try {
                $mb->guard($t, $mb->units($probe));
            } catch (OverBudget $e) {
                $this->blockFrom($e, ['kind' => 'task', 'taskId' => $t->id, 'newUnits' => $mb->units($probe), 'title' => $t->title]);
                $this->addError('budget_amount', $e->getMessage()); // keeps the panel open

                return;
            }
        }
        $t->update($data);
        $this->dismissBlock();
    }

    protected function retryBlocked(): void
    {
        $this->saveEstimate($this->task);
        $this->getErrorBag()->any() || $this->closePanel();
    }

    /** The client confirmed the add-on: raises the module budget by its value and counts it from now on. */
    public function acceptAddon(): void
    {
        abort_unless($this->canMoney, 403);
        $this->authorize('update', $this->task->module->project);
        try {
            $this->task->acceptAddon(auth()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('addon', $e->errors()['addon'][0]);
        }
        $this->task->refresh();
    }

    public function moveColumn(int $columnId): void
    {
        $this->authorize('update', $this->task);
        abort_unless($this->task->module->project->columns()->whereKey($columnId)->exists(), 422);
        $this->task->update(['board_column_id' => $columnId]);
    }

    /** Put the task in a planned/active sprint of its project, or take it out (0). Done tasks stay with their sprint. */
    public function setSprint(int $sprintId): void
    {
        $this->authorize('update', $this->task);
        $project = $this->itemProject();
        abort_if($this->task->column?->stage === 'done' || $this->task->sprint?->status === 'completed', 422);
        $sprint = $sprintId ? $project->sprints()->whereIn('status', ['planned', 'active'])->find($sprintId) : null;
        abort_if($sprintId && ! $sprint, 422);
        $this->task->update(['sprint_id' => $sprint?->id]);
    }

    // ── subtasks ──────────────────────────────────────────────────────────────

    public function addSubtask(): void
    {
        $this->authorize('update', $this->task);
        $this->validate(['newSubtask' => 'required|string|max:255']);
        Subtask::create(['task_id' => $this->task->id, 'title' => trim($this->newSubtask)]);
        $this->reset('newSubtask');
        $this->dispatch('subtask-added'); // closes the add-subtask input
    }

    public function reorderSubtasks(array $ids): void
    {
        $this->authorize('update', $this->task);
        Subtask::reorder($ids, $this->task->id);
    }

    /** The board moved or re-assigned this task: just re-render. */
    #[On('board-task-changed')]
    public function boardChanged(): void {}

    #[On('checklist-changed')]
    public function refreshProgress(): void {}

    public function toggleSubtask(int $id): void
    {
        $this->authorize('update', $this->task);
        $s = $this->task->subtasks()->findOrFail($id);
        $s->update(['is_done' => ! $s->is_done]);
    }

    public function render()
    {
        $project = $this->itemProject();
        $this->task->unsetRelation('checklistItems')->unsetRelation('subtasks')->unsetRelation('column')->unsetRelation('type')->unsetRelation('sprint');
        if ($this->embedded && $this->hydrated) {
            $this->dispatch('task-panel-updated');
        }
        $subtasks = $this->task->subtasks()->withCount('comments')->with('assignees')->get();

        return view('livewire.tasks.show', $this->panelData() + [
            'project' => $project,
            'module' => $this->task->module,
            'columns' => $project->columns,
            'sprints' => $project->sprints()->whereIn('status', ['planned', 'active'])->get(),
            'types' => $this->panel === 'type' ? \App\Models\TaskType::orderBy('position')->get() : collect(),
            'subtasks' => $subtasks,
            'attachments' => $this->task->attachments()->latest('id')->get(),
            'canCopy' => auth()->user()->can('update', $project),
            'pct' => app(ProgressService::class)->forTask($this->task),
            'bv' => $this->blockView(),
            'mb' => $this->canMoney ? new ModuleBudget($this->task->module) : null,
            'logged' => $this->canMoney ? round((float) $this->task->timeEntries()->sum('hours') + (float) $this->task->subtasks->sum(fn ($s) => $s->timeEntries()->sum('hours')), 2) : 0,
        ]);
    }
}

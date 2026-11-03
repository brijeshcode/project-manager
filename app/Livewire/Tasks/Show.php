<?php

namespace App\Livewire\Tasks;

use App\Livewire\Concerns\ItemPage;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use ItemPage;

    public Task $task;

    #[Locked]
    public bool $canEdit = false;

    #[Locked]
    public bool $canCost = false;

    public string $deadline = '';

    public string $deadlineReason = '';

    public int $priority = 0;

    public bool $clientVisible = false;

    public string $status = 'published';

    public bool $isIdea = false;

    public string $scope = 'in';

    public string $estimate = '0';

    public string $price = '';

    public string $newSubtask = '';

    public function mount(Task $task): void
    {
        $user = auth()->user();
        // same visibility as lists/board: drafts of others and out-of-section tasks are 404
        abort_unless(Task::visibleTo($user)->whereKey($task->id)->exists(), 404);
        $this->authorize('view', $task->module->project);
        $this->authorize('view', $task);
        $this->task = $task;
        $this->canEdit = $user->can('update', $task);
        $this->canCost = $user->can('updateCostTime', $task);
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
        $this->clientVisible = $t->client_visible;
        $this->status = $t->status->value;
        $this->isIdea = $t->is_idea;
        $this->scope = $t->scope->value;
        $this->estimate = (string) $t->estimate_hours;
        $this->price = $t->price === null ? '' : (string) $t->price;
    }

    protected function saveExtra(string $panel): void
    {
        $t = $this->task;
        match ($panel) {
            'deadline' => $this->saveDeadline($t),
            'priority' => $t->update($this->validate(['priority' => 'integer|in:0,1,2'])),
            'visibility' => $this->saveVisibility($t),
            'estimate' => $this->saveEstimate($t),
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

    private function saveEstimate(Task $t): void
    {
        abort_unless($this->canCost, 403);
        $this->validate(['scope' => 'required|in:in,out', 'estimate' => 'numeric|min:0|max:99999', 'price' => 'nullable|numeric|min:0|max:9999999.99']);
        $t->update(['scope' => $this->scope, 'estimate_hours' => (float) $this->estimate, 'price' => $this->price === '' ? null : (float) $this->price]);
    }

    public function moveColumn(int $columnId): void
    {
        $this->authorize('update', $this->task);
        abort_unless($this->task->module->project->columns()->whereKey($columnId)->exists(), 422);
        $this->task->update(['board_column_id' => $columnId]);
    }

    // ── subtasks ──────────────────────────────────────────────────────────────

    public function addSubtask(): void
    {
        $this->authorize('update', $this->task);
        $this->validate(['newSubtask' => 'required|string|max:255']);
        Subtask::create(['task_id' => $this->task->id, 'title' => trim($this->newSubtask)]);
        $this->reset('newSubtask');
    }

    public function toggleSubtask(int $id): void
    {
        $this->authorize('update', $this->task);
        $s = $this->task->subtasks()->findOrFail($id);
        $s->update(['is_done' => ! $s->is_done]);
    }

    public function render()
    {
        $project = $this->itemProject();
        $subtasks = $this->task->subtasks()->withCount('comments')->with('assignees')->get();

        return view('livewire.tasks.show', $this->panelData() + [
            'project' => $project,
            'module' => $this->task->module,
            'columns' => $project->columns,
            'subtasks' => $subtasks,
            'attachments' => $this->task->attachments()->latest('id')->get(),
            'canCopy' => auth()->user()->can('update', $project),
        ]);
    }
}

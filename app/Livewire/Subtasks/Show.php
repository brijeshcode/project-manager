<?php

namespace App\Livewire\Subtasks;

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

    public Subtask $subtask;

    #[Locked]
    public bool $canEdit = false;

    public string $estimate = '0';

    public function mount(Subtask $subtask): void
    {
        $user = auth()->user();
        $task = $subtask->task;
        // same visibility and 404 pattern as the parent task page
        abort_unless(Task::visibleTo($user)->whereKey($task->id)->exists(), 404);
        $this->authorize('view', $task->module->project);
        $this->authorize('view', $task);
        $this->subtask = $subtask;
        $this->canEdit = $user->can('update', $task);
    }

    protected function item(): Model
    {
        return $this->subtask;
    }

    protected function itemProject(): Project
    {
        return $this->subtask->task->module->project;
    }

    protected function authorizeEdit(): void
    {
        $this->authorize('update', $this->subtask->task);
    }

    protected function fillPanel(string $panel): void
    {
        $this->estimate = (string) $this->subtask->estimate_hours;
    }

    protected function saveExtra(string $panel): void
    {
        if ($panel === 'estimate') {
            $this->validate(['estimate' => 'numeric|min:0|max:99999']);
            $this->subtask->update(['estimate_hours' => (float) $this->estimate]);
        }
    }

    public function toggleDone(): void
    {
        $this->authorizeEdit();
        $this->subtask->update(['is_done' => ! $this->subtask->is_done]);
    }

    public function deleteSubtask()
    {
        $this->authorizeEdit();
        $task = $this->subtask->task;
        $this->subtask->delete();

        return $this->redirect(route('tasks.show', $task), navigate: true);
    }

    public function convertToTask()
    {
        $this->authorizeEdit();
        $task = $this->subtask->convertToTask(auth()->user());

        return $this->redirect(route('tasks.show', $task), navigate: true);
    }

    public function render()
    {
        $task = $this->subtask->task;

        return view('livewire.subtasks.show', $this->panelData() + [
            'task' => $task,
            'project' => $this->itemProject(),
            'attachments' => $this->subtask->attachments()->latest('id')->get(),
        ]);
    }
}

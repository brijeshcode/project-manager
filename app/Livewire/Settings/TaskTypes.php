<?php

namespace App\Livewire\Settings;

use App\Models\Task;
use App\Models\TaskType;
use App\Support\Icon;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Super-admin page: create, edit, delete and reorder task types. */
class TaskTypes extends Component
{
    /** null = form closed, 0 = creating, id = editing. */
    public ?int $editing = null;

    public string $name = '';

    public string $color = '#6b7280';

    public ?string $icon = null;

    /** Type being deleted, and the type its tasks move to. */
    public ?int $deleting = null;

    public ?int $replacement = null;

    public function mount(): void
    {
        $this->authorize('manage-taxonomy');
    }

    public function create(): void
    {
        $this->authorize('manage-taxonomy');
        $this->reset('name', 'icon', 'deleting');
        $this->color = '#6b7280';
        $this->resetErrorBag();
        $this->editing = 0;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-taxonomy');
        $t = TaskType::findOrFail($id);
        [$this->name, $this->color, $this->icon, $this->deleting] = [$t->name, $t->color, $t->icon, null];
        $this->resetErrorBag();
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->reset('editing', 'deleting', 'replacement');
    }

    public function save(): void
    {
        $this->authorize('manage-taxonomy');
        abort_if($this->editing === null, 422);
        $this->name = trim($this->name);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('task_types', 'name')->ignore($this->editing ?: null)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => Icon::rules(),
        ]);
        $data['icon'] = $data['icon'] ?: null;
        if ($this->editing) {
            TaskType::findOrFail($this->editing)->update($data);
        } else {
            TaskType::create($data + ['position' => (int) TaskType::max('position') + 1]);
        }
        $this->cancel();
    }

    /** Drag-reorder: positions follow the order of the given ids. */
    public function reorder(array $ids): void
    {
        $this->authorize('manage-taxonomy');
        foreach (array_values(array_map('intval', $ids)) as $i => $id) {
            TaskType::whereKey($id)->update(['position' => $i + 1]);
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('manage-taxonomy');
        TaskType::findOrFail($id);
        $this->editing = null;
        $this->replacement = null;
        $this->deleting = $id;
        $this->resetErrorBag();
    }

    public function delete(): void
    {
        $this->authorize('manage-taxonomy');
        $type = TaskType::findOrFail($this->deleting);
        $used = $type->tasks()->count();

        if (TaskType::count() <= 1) {
            $this->addError('replacement', 'At least one task type must remain.');

            return;
        }
        if ($used && $type->is_system) {
            $this->addError('replacement', "{$type->name} is a built-in type and tasks still use it. Edit it instead.");

            return;
        }
        if ($used) {
            $this->validate(['replacement' => ['required', Rule::exists('task_types', 'id'), Rule::notIn([$type->id])]], ['replacement.required' => 'Choose the type these tasks move to.']);
            Task::where('task_type_id', $type->id)->update(['task_type_id' => $this->replacement]);
        }
        $type->delete();
        $this->cancel();
    }

    public function render()
    {
        return view('livewire.settings.task-types', [
            'types' => TaskType::withCount('tasks')->orderBy('position')->orderBy('id')->get(),
        ]);
    }
}

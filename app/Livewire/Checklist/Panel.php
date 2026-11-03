<?php

namespace App\Livewire\Checklist;

use App\Models\ChecklistItem;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Checklist card of a task or subtask: add, tick, rename, delete, drag to reorder, n/m count. */
class Panel extends Component
{
    /** The fully-qualified morph type (Task or Subtask). */
    #[Locked]
    public string $checklistableType = '';

    #[Locked]
    public int $checklistableId = 0;

    public string $newTitle = '';

    public ?int $editingId = null;

    public string $editTitle = '';

    public function mount(Model $checklistable): void
    {
        abort_unless($checklistable instanceof Task || $checklistable instanceof Subtask, 404);
        $this->checklistableType = get_class($checklistable);
        $this->checklistableId = $checklistable->id;
        $task = $this->task();
        $this->authorize('view', $task->module->project);
        $this->authorize('view', $task);
    }

    private function task(): Task
    {
        $owner = $this->checklistableType::findOrFail($this->checklistableId);

        return $owner instanceof Subtask ? $owner->task : $owner;
    }

    private function items()
    {
        return ChecklistItem::where('checklistable_type', $this->checklistableType)->where('checklistable_id', $this->checklistableId);
    }

    private function item(int $id): ChecklistItem
    {
        $this->authorize('update', $this->task());

        return $this->items()->findOrFail($id);
    }

    private function changed(): void
    {
        $this->dispatch('checklist-changed'); // the page's progress bar listens
    }

    public function addItem(): void
    {
        $this->authorize('update', $this->task());
        $this->validate(['newTitle' => 'required|string|max:255']);

        ChecklistItem::create([
            'checklistable_type' => $this->checklistableType,
            'checklistable_id' => $this->checklistableId,
            'title' => trim($this->newTitle),
            'is_done' => false,
            'position' => ChecklistItem::nextPosition($this->checklistableType, $this->checklistableId),
        ]);

        $this->newTitle = '';
        $this->changed();
    }

    public function toggleItem(int $itemId): void
    {
        $item = $this->item($itemId);
        $item->update(['is_done' => ! $item->is_done]);
        $this->changed();
    }

    public function startRename(int $itemId): void
    {
        $this->editTitle = $this->item($itemId)->title;
        $this->editingId = $itemId;
    }

    public function saveRename(): void
    {
        $item = $this->item((int) $this->editingId);
        $this->validate(['editTitle' => 'required|string|max:255']);
        $item->update(['title' => trim($this->editTitle)]);
        $this->reset('editingId', 'editTitle');
    }

    public function deleteItem(int $itemId): void
    {
        $this->item($itemId)->delete();
        $this->changed();
    }

    public function reorderItems(array $orderedIds): void
    {
        $this->authorize('update', $this->task());
        $orderedIds = array_map('intval', $orderedIds);
        if ($orderedIds) {
            ChecklistItem::reorder($orderedIds, $this->checklistableType, $this->checklistableId);
        }
    }

    public function render()
    {
        return view('livewire.checklist.panel', [
            'items' => $this->items()->orderBy('position')->get(),
            'canEdit' => auth()->user()->can('update', $this->task()),
        ]);
    }
}

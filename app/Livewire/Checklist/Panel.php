<?php

namespace App\Livewire\Checklist;

use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class Panel extends Component
{
    /** The fully-qualified morph type (e.g. App\Models\Task). */
    public string $checklistableType = '';
    public int    $checklistableId   = 0;
    public string $newTitle          = '';

    public function mount(Model $checklistable): void
    {
        $this->checklistableType = get_class($checklistable);
        $this->checklistableId   = $checklistable->id;
    }

    public function addItem(): void
    {
        $this->validate(['newTitle' => 'required|string|max:255']);

        ChecklistItem::create([
            'checklistable_type' => $this->checklistableType,
            'checklistable_id'   => $this->checklistableId,
            'title'              => $this->newTitle,
            'is_done'            => false,
            'position'           => ChecklistItem::nextPosition($this->checklistableType, $this->checklistableId),
        ]);

        $this->newTitle = '';
    }

    public function toggleItem(int $itemId): void
    {
        $item = ChecklistItem::where('id', $itemId)
            ->where('checklistable_type', $this->checklistableType)
            ->where('checklistable_id', $this->checklistableId)
            ->firstOrFail();

        $item->update(['is_done' => ! $item->is_done]);
    }

    public function reorderItems(array $orderedIds): void
    {
        if (empty($orderedIds)) {
            return;
        }

        ChecklistItem::reorder($orderedIds, $this->checklistableType, $this->checklistableId);
    }

    public function render()
    {
        return view('livewire.checklist.panel', [
            'items' => ChecklistItem::where('checklistable_type', $this->checklistableType)
                ->where('checklistable_id', $this->checklistableId)
                ->orderBy('position')
                ->get(),
        ]);
    }
}

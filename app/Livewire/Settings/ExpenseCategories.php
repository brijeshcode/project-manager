<?php

namespace App\Livewire\Settings;

use App\Models\ExpenseCategory;
use App\Support\Icon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Money page: add, edit (name, colour, icon or image), reorder and delete expense categories. */
class ExpenseCategories extends Component
{
    use WithFileUploads;

    /** null = form closed, 0 = creating, id = editing. */
    public ?int $editing = null;

    public string $name = '';

    public string $color = '#6b7280';

    public ?string $icon = null;

    /** The stored image of the category being edited; removeImage() clears it, applied on save. */
    public ?string $icon_path = null;

    /** A newly chosen image; wins over the icon. */
    public $image = null;

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    public function create(): void
    {
        $this->authorize('manage-money');
        $this->reset('name', 'icon', 'icon_path', 'image');
        $this->color = '#6b7280';
        $this->resetErrorBag();
        $this->editing = 0;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-money');
        $c = ExpenseCategory::findOrFail($id);
        [$this->name, $this->color, $this->icon, $this->icon_path, $this->image] = [$c->name, $c->color, $c->icon, $c->icon_path, null];
        $this->resetErrorBag();
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->reset('editing', 'name', 'image');
    }

    public function removeImage(): void
    {
        $this->reset('icon_path', 'image');
    }

    public function save(): void
    {
        $this->authorize('manage-money');
        abort_if($this->editing === null, 422);
        $this->name = trim($this->name);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('expense_categories', 'name')->ignore($this->editing ?: null)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => Icon::rules(),
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:512'],
        ]);
        unset($data['image']);
        $data['icon'] = $data['icon'] ?: null;
        $data['icon_path'] = $this->image ? $this->image->store('expense-category-icons', 'public') : $this->icon_path;
        if ($this->editing) {
            $c = ExpenseCategory::findOrFail($this->editing);
            $old = $c->icon_path;
            $c->update($data);
            $old && $old !== $c->icon_path && Storage::disk('public')->delete($old);
        } else {
            ExpenseCategory::create($data + ['position' => (int) ExpenseCategory::max('position') + 1]);
        }
        $msg = $this->editing ? 'Category saved.' : 'Category added.';
        $this->cancel();
        $this->dispatch('toast', message: $msg);
    }

    /** Drag-reorder: positions follow the order of the given ids. */
    public function reorder(array $ids): void
    {
        $this->authorize('manage-money');
        foreach (array_values(array_map('intval', $ids)) as $i => $id) {
            ExpenseCategory::whereKey($id)->update(['position' => $i + 1]);
        }
    }

    /** Like labels: expenses that used it are left without a category. $id comes from the confirm dialog. */
    public function delete(int $id): void
    {
        $this->authorize('manage-money');
        $c = ExpenseCategory::findOrFail($id);
        $c->expenses()->update(['expense_category_id' => null]);
        $c->delete();
        $c->icon_path && Storage::disk('public')->delete($c->icon_path);
        $this->cancel();
        $this->dispatch('toast', message: 'Category deleted.');
    }

    public function render()
    {
        return view('livewire.settings.expense-categories', [
            'categories' => ExpenseCategory::withCount('expenses')->orderBy('position')->orderBy('id')->get(),
        ]);
    }
}

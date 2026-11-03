<?php

namespace App\Livewire\Settings;

use App\Models\Label;
use App\Support\Icon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Super-admin page: create, edit and delete labels. Everyone else only assigns them. */
class Labels extends Component
{
    /** null = form closed, 0 = creating, id = editing. */
    public ?int $editing = null;

    public string $name = '';

    public string $color = '#6b7280';

    public ?string $icon = null;

    public ?int $deleting = null;

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
        $l = Label::findOrFail($id);
        [$this->name, $this->color, $this->icon, $this->deleting] = [$l->name, $l->color, $l->icon, null];
        $this->resetErrorBag();
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->reset('editing', 'deleting');
    }

    public function save(): void
    {
        $this->authorize('manage-taxonomy');
        abort_if($this->editing === null, 422);
        $this->name = trim($this->name);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('labels', 'name')->ignore($this->editing ?: null)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => Icon::rules(),
        ]);
        $data['icon'] = $data['icon'] ?: null;
        $this->editing ? Label::findOrFail($this->editing)->update($data) : Label::create($data);
        $this->cancel();
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('manage-taxonomy');
        Label::findOrFail($id);
        $this->editing = null;
        $this->deleting = $id;
    }

    /** Deleting a label simply removes it from everything it was attached to. */
    public function delete(): void
    {
        $this->authorize('manage-taxonomy');
        $label = Label::findOrFail($this->deleting);
        DB::table('labelables')->where('label_id', $label->id)->delete();
        $label->delete();
        $this->cancel();
    }

    public function render()
    {
        return view('livewire.settings.labels', [
            'labels' => Label::orderBy('name')->get()->each(fn ($l) => $l->uses = DB::table('labelables')->where('label_id', $l->id)->count()),
        ]);
    }
}

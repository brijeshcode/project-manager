<?php

namespace App\Livewire\Modules;

use App\Models\Module;
use App\Models\Project;
use App\Livewire\Concerns\HandlesMarkdownUploads;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class Manager extends Component
{
    use HandlesMarkdownUploads;

    protected function uploadTarget(): Model
    {
        abort(403); // text-only editor; files are attached on the module page
    }

    public Project $project;
    public string  $newName = '';
    public string  $newDescription = '';

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);
        $this->project = $project;
    }

    public function createModule(): void
    {
        $this->validate(['newName' => 'required|string|max:255', 'newDescription' => 'nullable|string|max:20000']);

        Module::create([
            'project_id' => $this->project->id,
            'name'       => $this->newName,
            'description' => trim($this->newDescription) ?: null,
            'position'   => Module::nextPosition($this->project->id),
        ]);

        $this->reset('newName', 'newDescription');
    }

    public function reorderModules(array $orderedIds): void
    {
        if (empty($orderedIds)) {
            return;
        }

        Module::reorder($orderedIds, $this->project->id);
    }

    public function render()
    {
        return view('livewire.modules.manager', [
            'modules' => $this->project->modules()->get(),
        ]);
    }
}

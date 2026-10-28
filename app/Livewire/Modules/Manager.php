<?php

namespace App\Livewire\Modules;

use App\Models\Module;
use App\Models\Project;
use Livewire\Component;

class Manager extends Component
{
    public Project $project;
    public string  $newName = '';

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);
        $this->project = $project;
    }

    public function createModule(): void
    {
        $this->validate(['newName' => 'required|string|max:255']);

        Module::create([
            'project_id' => $this->project->id,
            'name'       => $this->newName,
            'position'   => Module::nextPosition($this->project->id),
        ]);

        $this->newName = '';
    }

    public function reorderModules(array $orderedIds): void
    {
        Module::reorder($orderedIds);
    }

    public function render()
    {
        return view('livewire.modules.manager', [
            'modules' => $this->project->modules()->get(),
        ]);
    }
}

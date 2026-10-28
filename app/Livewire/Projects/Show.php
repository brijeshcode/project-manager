<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function render()
    {
        return view('livewire.projects.show', [
            'project' => $this->project->load(['client', 'members']),
        ]);
    }
}

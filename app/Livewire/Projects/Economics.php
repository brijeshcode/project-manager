<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\ProjectEconomics;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Economics extends Component
{
    #[Locked]
    public int $projectId;

    /** Shown inside the project page, whose breadcrumb already names the project. */
    #[Locked]
    public bool $embedded = false;

    public function mount(Project $project): void
    {
        $this->authorize('manage-money');
        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::with('client')->findOrFail($this->projectId);
    }

    #[Computed]
    public function economics(): array
    {
        $this->authorize('manage-money');

        return ProjectEconomics::for($this->project);
    }

    /** All modules with their budgeted tasks, for the budget breakdown. */
    #[Computed]
    public function budgetModules()
    {
        $modules = $this->project->modules()->approved()
            ->with(['tasks' => fn ($q) => $q->budgeted()->orderBy('position')->orderBy('id'), 'tasks.column'])->get();
        $modules->flatMap->tasks->each->setRelation('project', $this->project);

        return $modules;
    }

    public function render()
    {
        return view('livewire.projects.economics');
    }
}

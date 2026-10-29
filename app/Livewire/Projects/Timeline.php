<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\ProgressService;
use Livewire\Component;

class Timeline extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function render()
    {
        $checklistProgress = app(ProgressService::class)->forProject($this->project);
        $timeBar           = $this->project->timeBar();
        $estimateVsActual  = $this->project->estimateVsActual();

        return view('livewire.projects.timeline', [
            'checklistProgress' => $checklistProgress,
            'timeBar'           => $timeBar,
            'estimated'         => $estimateVsActual['estimated'],
            'logged'            => $estimateVsActual['logged'],
        ]);
    }
}

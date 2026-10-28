<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Project;
use Livewire\Component;

class Form extends Component
{
    public ?int $projectId = null;

    public int    $client_id      = 0;
    public string $name           = '';
    public float  $buffer_percent = 0;
    public ?string $start_date    = null;
    public string $status         = 'draft';

    public function mount(?Project $project = null): void
    {
        if ($project && $project->exists) {
            $this->authorize('update', $project);
            $this->projectId      = $project->id;
            $this->client_id      = $project->client_id;
            $this->name           = $project->name;
            $this->buffer_percent = (float) $project->buffer_percent;
            $this->start_date     = $project->start_date?->toDateString();
            $this->status         = $project->status;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'client_id'      => 'required|exists:clients,id',
            'name'           => 'required|string|max:255',
            'buffer_percent' => 'numeric|min:0|max:999.99',
            'start_date'     => 'nullable|date',
            'status'         => 'required|string|max:50',
        ]);

        if ($this->projectId) {
            $project = Project::findOrFail($this->projectId);
            $this->authorize('update', $project);
            $project->update($data);
        } else {
            Project::create($data);
        }

        $this->redirect(route('projects.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.projects.form', [
            'clients' => Client::orderBy('name')->get(),
        ]);
    }
}

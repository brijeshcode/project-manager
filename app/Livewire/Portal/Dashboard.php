<?php

namespace App\Livewire\Portal;

use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Dashboard extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
    }

    /**
     * Projects belonging to this client user's client — never another client's.
     * ponytail: scoped by client_id FK, no cost/price fields selected.
     */
    #[Computed]
    public function projects(): Collection
    {
        $clientId = auth()->user()->client_id;

        abort_if(! $clientId, 403);

        return Project::where('client_id', $clientId)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'client_id', 'start_date']);
    }

    public function render()
    {
        return view('livewire.portal.dashboard', [
            'projects' => $this->projects,
        ])->layout('layouts.app');
    }
}

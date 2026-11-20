<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\FiltersStatement;
use Livewire\Component;

class Statement extends Component
{
    use FiltersStatement;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
        abort_unless(auth()->user()->client->portalShows('statement'), 404);
    }

    public function render()
    {
        $client = auth()->user()->client;

        return view('livewire.portal.statement', ['client' => $client, 'statement' => $this->statement($client)])->layout('layouts.app');
    }
}

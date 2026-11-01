<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\WithListSearch;
use App\Models\Client;
use Livewire\Component;

class Index extends Component
{
    use WithListSearch;

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);
    }

    public function render()
    {
        $like = $this->likeTerm();

        return view('livewire.clients.index', [
            'clients' => $this->fitPage(
                Client::orderBy('name')->when($like, fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$like])),
                15
            ),
        ]);
    }
}

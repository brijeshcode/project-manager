<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('livewire.clients.index', [
            'clients' => Client::orderBy('name')->get(),
        ]);
    }
}

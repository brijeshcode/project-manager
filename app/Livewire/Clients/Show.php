<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\DeletesClient;
use App\Models\Client;
use App\Services\ClientAccount;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use DeletesClient;

    #[Locked]
    public int $clientId;

    public function mount(Client $client): void
    {
        $this->authorize('view', $client);
        $this->clientId = $client->id;
    }

    /** The argument is ignored: this page can only delete its own client. */
    public function delete(?int $clientId = null): void
    {
        if ($this->deleteClient($this->clientId)) {
            session()->flash('toast', 'Client deleted.');
            $this->redirect(route('clients.index'), navigate: true);
        }
    }

    public function render()
    {
        $client = Client::findOrFail($this->clientId);
        $this->authorize('view', $client);
        $t = app(ClientAccount::class)->totals([$client->id])[$client->id];

        return view('livewire.clients.show', [
            'client' => $client,
            'billed' => $t['billed'],
            'received' => $t['received'],
            'projects' => $client->projects()->orderBy('name')->get(),
            'payments' => $client->payments()->with(['project:id,name', 'invoice:id,number'])->latest('paid_on')->latest('id')->get(),
            'invoices' => $client->invoices()->latest('issued_on')->latest('id')->get(),
        ]);
    }
}

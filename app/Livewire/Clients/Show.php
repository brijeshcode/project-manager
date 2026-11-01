<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\DeletesClient;
use App\Models\Client;
use App\Models\Payment;
use App\Services\ClientAccount;
use App\Services\LocalCurrency;
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

        $code = LocalCurrency::code();
        $invoices = $client->invoices()->latest('issued_on')->latest('id')->get();

        // Local lines: null (hidden) when the client is already in local currency or no local figure exists yet.
        $billedLocal = $receivedLocal = null;
        if (strtoupper($client->currency) !== $code) {
            $sent = $invoices->where('status', 'sent');
            $billedLocal = $sent->sum(fn ($i) => $i->localTotalCents() ?? 0) ?: null;
            $receivedLocal = (int) Payment::where('client_id', $client->id)->where('local_currency', $code)->sum('local_amount_cents') ?: null;
        }

        return view('livewire.clients.show', [
            'client' => $client,
            'localCode' => $code,
            'billedLocal' => $billedLocal,
            'receivedLocal' => $receivedLocal,
            'billed' => $t['billed'],
            'received' => $t['received'],
            'projects' => $client->projects()->orderBy('name')->get(),
            'payments' => $client->payments()->with(['project:id,name', 'invoice:id,number'])->latest('paid_on')->latest('id')->get(),
            'invoices' => $invoices,
        ]);
    }
}

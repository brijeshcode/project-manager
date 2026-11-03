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

        // Local lines: hidden for a same-currency client; with any missing rate a note replaces the (otherwise partial) total.
        $billedLocal = $receivedLocal = null;
        $billedMissing = $receivedMissing = 0;
        if (strtoupper($client->currency) !== $code) {
            $sent = $invoices->where('status', 'sent');
            $billedMissing = $sent->whereNull('exchange_rate')->count();
            $billedLocal = $sent->sum(fn ($i) => $i->localTotalCents() ?? 0);
            $r = Payment::where('client_id', $client->id)
                ->selectRaw('COALESCE(SUM(CASE WHEN local_currency = ? THEN local_amount_cents ELSE 0 END), 0) as l, COALESCE(SUM(CASE WHEN local_amount_cents IS NULL OR local_currency <> ? THEN 1 ELSE 0 END), 0) as m', [$code, $code])->first();
            $receivedLocal = (int) $r->l;
            $receivedMissing = (int) $r->m;
        }

        return view('livewire.clients.show', [
            'client' => $client,
            'localCode' => $code,
            'billedLocal' => $billedLocal,
            'receivedLocal' => $receivedLocal,
            'billedMissing' => $billedMissing,
            'receivedMissing' => $receivedMissing,
            'billed' => $t['billed'],
            'received' => $t['received'],
            'projects' => $client->projects()->orderBy('name')->get(),
            'payments' => $client->payments()->with(['project:id,name', 'documents'])->latest('paid_on')->latest('id')->get(),
            'invoices' => $invoices->where('status', '!=', 'draft'),
            'futureInvoices' => $invoices->where('status', 'draft'),
        ]);
    }
}

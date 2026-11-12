<?php

namespace App\Livewire\Portal;

use App\Models\RecurringCharge;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SubscriptionShow extends Component
{
    #[Locked]
    public int $chargeId;

    public string $note = '';

    public function mount(int $charge): void
    {
        $this->chargeId = $this->charge($charge)->id;
    }

    /** The client's own subscription, or 404. */
    private function charge(?int $id = null): RecurringCharge
    {
        abort_unless(auth()->user()->hasRole('client') && auth()->user()->client_id, 403);
        $charge = RecurringCharge::where('client_id', auth()->user()->client_id)->find($id ?? $this->chargeId);
        abort_unless($charge, 404);

        return $charge;
    }

    public function approve(): void
    {
        $this->validate(['note' => 'required|string|max:2000']);
        $this->charge()->approveByClient(trim($this->note));
        $this->reset('note');
        $this->dispatch('toast', type: 'success', message: 'Subscription approved. It is now active.');
    }

    public function requestCancel(): void
    {
        $this->validate(['note' => 'required|string|max:2000']);
        $this->charge()->requestCancel(trim($this->note));
        $this->reset('note');
        $this->dispatch('toast', type: 'success', message: 'Request sent. We will confirm the cancellation.');
    }

    public function render()
    {
        $charge = $this->charge();
        $charge = RecurringCharge::withUsage()->findOrFail($charge->id);
        $client = auth()->user()->client;
        $project = $charge->project_id ? $client->projects()->find($charge->project_id, ['id', 'name', 'currency']) : null;

        return view('livewire.portal.subscription-show', [
            'charge' => $charge,
            'project' => $project,
            'cur' => $project?->currency ?? $client->currency,
            // sent invoices only; amounts in the subscription's currency, never local
            'usages' => $charge->lines()->whereHas('invoice', fn ($i) => $i->where('status', 'sent'))
                ->with('invoice:id,number,issued_on')->get(['id', 'invoice_id', 'period', 'amount_cents'])
                ->sortByDesc(fn ($l) => $l->invoice->issued_on->toDateString().' '.$l->period)->values(),
        ])->layout('layouts.app');
    }
}

<?php

namespace App\Livewire\Portal;

use App\Models\Payment;
use Livewire\Component;
use Livewire\WithPagination;

class Payments extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
    }

    public function render()
    {
        // notes deliberately not selected
        $cid = auth()->user()->client_id;
        $payments = Payment::where('client_id', $cid)
            ->with([
                'project' => fn ($q) => $q->where('client_id', $cid)->select('id', 'name', 'client_id'),
            ])
            ->latest('paid_on')->latest('id')
            ->paginate(\App\Support\Settings::pageSize(), ['id', 'client_id', 'project_id', 'currency_id', 'amount_cents', 'paid_on', 'type', 'method']);

        return view('livewire.portal.payments', [
            'payments' => $payments,
            'currency' => auth()->user()->client->currency,
        ])->layout('layouts.app');
    }
}

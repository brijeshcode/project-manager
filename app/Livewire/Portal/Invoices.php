<?php

namespace App\Livewire\Portal;

use App\Models\Invoice;
use Livewire\Component;
use Livewire\WithPagination;

class Invoices extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
    }

    public function render()
    {
        // sent only (void hidden); explicit columns; one withSum for paid state
        $invoices = Invoice::where('client_id', auth()->user()->client_id)
            ->where('status', 'sent')
            ->withSum('payments', 'amount_cents')
            ->latest('issued_on')->latest('id')
            ->paginate(15, ['id', 'number', 'client_id', 'issued_on', 'due_on', 'currency', 'total_cents']);

        return view('livewire.portal.invoices', ['invoices' => $invoices])->layout('layouts.app');
    }
}

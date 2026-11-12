<?php

namespace App\Livewire\Portal;

use App\Models\Invoice;
use Livewire\Attributes\Locked;
use Livewire\Component;

class InvoiceShow extends Component
{
    #[Locked]
    public int $invoiceId;

    public function mount(int $invoice): void
    {
        $this->invoiceId = $this->invoice($invoice)->id;
    }

    /** Own client's sent invoice only; anything else 404s. */
    private function invoice(int $id): Invoice
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);

        return Invoice::where('client_id', auth()->user()->client_id)->where('status', 'sent')->findOrFail($id);
    }

    public function render()
    {
        return view('livewire.portal.invoice-show', ['invoice' => $this->invoice($this->invoiceId)])->layout('layouts.app');
    }
}

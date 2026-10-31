<?php

namespace App\Livewire\Invoices;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $invoiceId;

    public function mount(Invoice $invoice): void
    {
        $this->authorize('manage-money');
        $this->invoiceId = $invoice->id;
    }

    public function void(InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $svc->void(Invoice::findOrFail($this->invoiceId));
    }

    public function render()
    {
        $this->authorize('manage-money');

        return view('livewire.invoices.show', ['invoice' => Invoice::with(['client', 'lines'])->findOrFail($this->invoiceId)]);
    }
}

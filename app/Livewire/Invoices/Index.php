<?php

namespace App\Livewire\Invoices;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\LocalCurrency;
use App\Livewire\Concerns\WithListSearch;
use Livewire\Component;

class Index extends Component
{
    use WithListSearch;

    public string $clientFilter = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $this->authorize('manage-money');

        $like = $this->likeTerm();
        // paid = sent with payments covering a positive total (same rule as the row badge)
        $paidSql = '(select coalesce(sum(amount_cents), 0) from payments where payments.invoice_id = invoices.id) >= invoices.total_cents and invoices.total_cents > 0';

        return view('livewire.invoices.index', [
            'invoices' => $this->fitPage(Invoice::with('client')->withSum('payments', 'amount_cents')
                ->when($this->clientFilter !== '', fn ($q) => $q->where('client_id', (int) $this->clientFilter))
                ->when($like, fn ($q) => $q->where(fn ($w) => $w
                    ->whereRaw("invoices.number LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))))
                ->when(in_array($this->status, ['sent', 'void']), fn ($q) => $q->where('invoices.status', $this->status))
                ->when($this->status === 'paid', fn ($q) => $q->where('invoices.status', 'sent')->whereRaw($paidSql))
                ->when($this->status === 'unpaid', fn ($q) => $q->where('invoices.status', 'sent')->whereRaw("not ($paidSql)"))
                ->orderByDesc('id'), 15),
            'local' => LocalCurrency::code(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }
}

<?php

namespace App\Livewire\Invoices;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Livewire\Concerns\WithDateFilter;
use App\Livewire\Concerns\WithListSearch;
use Livewire\Component;

class Index extends Component
{
    use WithListSearch, WithDateFilter;

    public string $clientFilter = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    /** Status by hand from the list: not sent or sent. Cancelling stays on the invoice page. */
    public function setStatus(int $invoiceId, string $status, InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $ok = $svc->setStatus(Invoice::findOrFail($invoiceId), $status);
        $this->dispatch('toast', type: $ok ? 'success' : 'error', message: $ok ? 'Status updated.' : 'This invoice cannot change status.');
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $this->authorize('manage-money');

        $like = $this->likeTerm();

        $filtered = $this->applyDateFilter(Invoice::query()
            ->when($this->clientFilter !== '', fn ($q) => $q->where('client_id', (int) $this->clientFilter))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("invoices.number LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))))
            ->when(in_array($this->status, ['sent', 'draft', 'void']), fn ($q) => $q->where('invoices.status', $this->status)), 'issued_on');

        return view('livewire.invoices.index', [
            'invoices' => $this->fitPage((clone $filtered)->with('client')->orderByDesc('id'), \App\Support\Settings::pageSize()),
            'local' => LocalCurrency::code(),
            'stats' => \App\Support\MoneyStats::invoices($filtered),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'period' => $this->activePeriod(),
        ]);
    }
}

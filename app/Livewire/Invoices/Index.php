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
        return view('livewire.invoices.index', [
            'invoices' => $this->fitPage(Invoice::with('client')
                ->when($this->clientFilter !== '', fn ($q) => $q->where('client_id', (int) $this->clientFilter))
                ->when($like, fn ($q) => $q->where(fn ($w) => $w
                    ->whereRaw("invoices.number LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))))
                ->when(in_array($this->status, ['sent', 'draft', 'void']), fn ($q) => $q->where('invoices.status', $this->status))
                ->orderByDesc('id'), 15),
            'local' => LocalCurrency::code(),
            'owing' => \App\Support\DashboardMoney::owingClientIds(),
            'stats' => \App\Support\MoneyStats::invoices(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }
}

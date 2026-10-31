<?php

namespace App\Livewire\Invoices;

use App\Models\Client;
use App\Models\Invoice;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

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

        return view('livewire.invoices.index', [
            'invoices' => Invoice::with('client')->withSum('payments', 'amount_cents')
                ->when($this->clientFilter !== '', fn ($q) => $q->where('client_id', (int) $this->clientFilter))
                ->orderByDesc('id')->paginate(20),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }
}

<?php

namespace App\Livewire\Portal;

use App\Models\Invoice;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Invoices extends Component
{
    use WithPagination;

    #[Url(as: 'view')]
    public string $view = 'list';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
    }

    public function render()
    {
        $this->view = in_array($this->view, ['cards', 'list'], true) ? $this->view : 'list';
        // sent only (void hidden); explicit columns; one withSum for paid state
        $invoices = Invoice::where('client_id', auth()->user()->client_id)
            ->where('status', 'sent')
            ->latest('issued_on')->latest('id')
            ->paginate(\App\Support\Settings::pageSize(), ['id', 'number', 'client_id', 'issued_on', 'due_on', 'currency_id', 'total_cents']);

        return view('livewire.portal.invoices', ['invoices' => $invoices])->layout('layouts.app');
    }
}

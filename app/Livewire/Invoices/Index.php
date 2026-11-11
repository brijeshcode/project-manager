<?php

namespace App\Livewire\Invoices;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Livewire\Concerns\WithListSearch;
use Livewire\Component;

class Index extends Component
{
    use WithListSearch;

    public string $clientFilter = '';

    #[\Livewire\Attributes\Url]
    public string $from = '';

    #[\Livewire\Attributes\Url]
    public string $to = '';

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

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    /** Issued-date shortcuts as [from, to]. ponytail: financial year fixed to April–March; make it a setting if other clients need another start. */
    private function periods(): array
    {
        $fy = today()->month >= 4 ? today()->setDate(today()->year, 4, 1) : today()->setDate(today()->year - 1, 4, 1);

        return array_map(fn ($r) => array_map(fn ($d) => $d->toDateString(), $r), [
            'month' => [today()->startOfMonth(), today()->endOfMonth()],
            'year' => [today()->startOfYear(), today()->endOfYear()],
            'fy' => [$fy, $fy->copy()->addYear()->subDay()],
        ]);
    }

    public function setPeriod(string $period): void
    {
        [$this->from, $this->to] = $this->periods()[$period] ?? ['', ''];
        $this->resetPage();
    }

    private function validDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    public function render()
    {
        $this->authorize('manage-money');

        $like = $this->likeTerm();

        $filtered = Invoice::query()
            ->when($this->clientFilter !== '', fn ($q) => $q->where('client_id', (int) $this->clientFilter))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("invoices.number LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))))
            ->when(in_array($this->status, ['sent', 'draft', 'void']), fn ($q) => $q->where('invoices.status', $this->status))
            ->when($this->validDate($this->from), fn ($q) => $q->whereDate('issued_on', '>=', $this->from))
            ->when($this->validDate($this->to), fn ($q) => $q->whereDate('issued_on', '<=', $this->to));

        return view('livewire.invoices.index', [
            'invoices' => $this->fitPage((clone $filtered)->with('client')->orderByDesc('id'), \App\Support\Settings::pageSize()),
            'local' => LocalCurrency::code(),
            'stats' => \App\Support\MoneyStats::invoices($filtered),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'period' => array_search([$this->from, $this->to], $this->periods(), true),
        ]);
    }
}

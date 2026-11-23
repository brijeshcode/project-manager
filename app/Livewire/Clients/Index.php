<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Livewire\Concerns\DeletesClient;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Services\ClientAccount;
use App\Services\LocalCurrency;
use App\Support\Money;
use App\Support\Settings;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use DeletesClient, WithListSearch;

    #[Url]
    public int|string $perPage = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);
        if (! request()->has('status')) {
            $this->status = 'active';
        }
        $this->perPage = $this->sizeOrDefault();
    }

    private function sizeOrDefault(): int
    {
        return in_array((int) $this->perPage, Settings::PAGE_SIZES, true) ? (int) $this->perPage : Settings::pageSize();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->sizeOrDefault();
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function delete(int $clientId): void
    {
        if ($this->deleteClient($clientId)) {
            $this->dispatch('toast', type: 'success', message: 'Client deleted.');
        }
    }

    public function render()
    {
        $like = $this->likeTerm();
        $perPage = $this->sizeOrDefault();

        $clients = $this->fitPage(
            // active first, then inactive, then archived; alphabetical within each
            Client::orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'inactive' THEN 1 ELSE 2 END")->orderBy('name')
                ->when(ClientStatus::tryFrom($this->status), fn ($q, $s) => $q->where('status', $s->value))
                ->when($like, fn ($q) => $q->where(fn ($q) => $q
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("company_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("client_code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("contact_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("contact_email LIKE ? ESCAPE '!'", [$like]))),
            $perPage
        );

        $account = app(ClientAccount::class);
        $allLocal = $account->localTotals(Client::pluck('id')->all());
        $active = RecurringCharge::withUsage()->where('active', true)->whereNull('client_state')->with(['client', 'project'])->get();
        $subs = $active->groupBy('client_id')->map(function ($g) {
            $cur = $g->first()->client->currency;
            $rate = LocalCurrency::rateFor($cur);

            return ['count' => $g->count(), 'invoices' => (int) $g->sum('invoiced_count'), 'local' => (int) $g->sum('local_amount_cents'),
                'amount' => $g->sum(fn ($c) => strtoupper($c->currency()) === strtoupper($cur) ? $c->amount_cents : ($rate && $c->local_amount_cents !== null ? Money::unconvert($c->local_amount_cents, $rate) : 0))];
        });

        return view('livewire.clients.index', [
            'localTotals' => $allLocal,
            'subs' => $subs,
            'localCode' => LocalCurrency::code(),
            'stats' => [
                'income' => array_sum(array_column($allLocal, 'received')),
                'balance' => array_sum(array_map(fn ($t) => $t['received'] - $t['billed'], $allLocal)),
                'subs' => (int) $active->sum('local_amount_cents'),
                'missing' => array_sum(array_column($allLocal, 'missing')) > 0,
            ],
            'clients' => $clients,
            'totals' => app(ClientAccount::class)->totals($clients->pluck('id')->all()),
            'perPage' => $perPage,
        ]);
    }
}

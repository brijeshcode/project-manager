<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentType;
use App\Livewire\Concerns\WithDateFilter;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Client;
use App\Models\Payment;
use App\Services\ClientAccount;
use App\Services\LocalCurrency;
use App\Support\Money;
use App\Support\MoneyStats;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use WithListSearch, WithDateFilter;

    #[Url(as: 'client')]
    public string $clientFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    /** @var array<int,string> legacy rows: typed rate per payment id */
    public array $rowRate = [];

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    /** Legacy rows only: the one narrow edit exception; a payment never gets a second local value. */
    public function setRate(int $paymentId): void
    {
        $this->authorize('manage-money');
        $p = Payment::with('client')->findOrFail($paymentId);
        if ($p->hasLocal()) {
            return;
        }
        $same = strtoupper($p->currency) === LocalCurrency::code();
        $rate = $same ? '1' : $this->validate(["rowRate.{$paymentId}" => ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')]])['rowRate'][$paymentId];
        $p->update(['exchange_rate' => $rate, 'local_amount_cents' => Money::convert($p->amount_cents, $rate), 'local_currency' => LocalCurrency::code()]);
        unset($this->rowRate[$paymentId]);
        Log::info('payment rate set', ['id' => $p->id, 'client_id' => $p->client_id, 'exchange_rate' => $rate, 'user_id' => auth()->id()]);
        $this->dispatch('toast', message: 'Rate saved.');
    }

    public function deletePayment(int $paymentId): void
    {
        $this->authorize('manage-money');
        $p = Payment::findOrFail($paymentId);
        $p->delete();
        // audit trail only: ids and amounts, never the free-text notes
        Log::info('payment deleted', ['id' => $p->id, 'client_id' => $p->client_id, 'amount_cents' => $p->amount_cents, 'paid_on' => $p->paid_on->toDateString(), 'user_id' => auth()->id()]);
        $this->dispatch('toast', message: 'Payment deleted.');
    }

    public function render()
    {
        $this->authorize('manage-money');

        $client = $this->clientFilter !== '' ? Client::find((int) $this->clientFilter) : null;
        $type = PaymentType::tryFrom($this->typeFilter);
        $filtered = $this->applyDateFilter(Payment::query()
            ->when($this->clientFilter !== '', fn ($q) => $q->where('client_id', (int) $this->clientFilter))
            ->when($type, fn ($q) => $q->where('type', $type->value)), 'paid_on');

        return view('livewire.payments.index', [
            'local' => LocalCurrency::code(),
            'stats' => MoneyStats::payments($filtered),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'types' => PaymentType::cases(),
            'period' => $this->activePeriod(),
            'payments' => $this->fitPage((clone $filtered)->with(['client', 'project'])->withCount('documents')
                ->orderByDesc('paid_on')->orderByDesc('id'), \App\Support\Settings::pageSize()),
            'balanceClient' => $client,
            'balance' => $client ? app(ClientAccount::class)->balanceCents($client) : null,
            'balanceLocal' => $client ? app(ClientAccount::class)->localBalanceCents($client) : null,
        ]);
    }
}

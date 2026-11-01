<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ClientAccount;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Livewire\Concerns\WithListSearch;
use Livewire\Component;

class Index extends Component
{
    use WithListSearch;

    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?int $invoice_id = null;
    public string $amount = '';
    public string $paid_on = '';
    public string $type = 'project';
    public string $method = '';
    public string $notes = '';
    public string $exchange_rate = '';
    public string $clientFilter = '';
    /** @var array<int,string> legacy rows: typed rate per payment id */
    public array $rowRate = [];

    public function mount(): void
    {
        $this->authorize('manage-money');
        $this->paid_on = today()->toDateString();
    }

    public function updatedClientId(): void
    {
        $this->reset('project_id', 'invoice_id');
        $client = $this->client_id ? Client::find($this->client_id) : null;
        $this->exchange_rate = $client ? self::tidy(LocalCurrency::rateFor($client->currency)) : '';
    }

    /** 83.500000 -> 83.5, 1 stays 1, null -> empty. */
    private static function tidy(?string $r): string
    {
        return $r === null ? '' : (str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r);
    }

    private function rateRules(): array
    {
        return ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')];
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $this->authorize('manage-money');

        $client = $this->client_id ? Client::find($this->client_id) : null;
        $same = $client && strtoupper($client->currency) === LocalCurrency::code();
        $data = $this->validate([
            'client_id'  => 'required|exists:clients,id',
            'exchange_rate' => $client && ! $same ? $this->rateRules() : 'nullable',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('client_id', $this->client_id)],
            'invoice_id' => ['nullable', Rule::exists('invoices', 'id')->where('client_id', $this->client_id)->where(fn ($q) => $q->where('status', 'sent'))],
            'amount'     => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.')],
            'paid_on'    => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'type'       => ['required', new Enum(PaymentType::class)],
            'method'     => 'nullable|string|max:100',
            'notes'      => 'nullable|string|max:500',
        ]);

        $rate = $same ? '1' : $data['exchange_rate'];
        $amountCents = Money::toCents($data['amount']);
        Payment::create([
            'exchange_rate' => $rate, 'local_amount_cents' => Money::convert($amountCents, $rate), 'local_currency' => LocalCurrency::code(),            'client_id' => $data['client_id'], 'project_id' => $data['project_id'] ?: null,
            'invoice_id' => $data['invoice_id'] ?: null, 'amount_cents' => $amountCents,
            'paid_on' => $data['paid_on'], 'type' => $data['type'],
            'method' => $data['method'] ?: null, 'notes' => $data['notes'] ?: null,
        ]);

        $this->reset('project_id', 'invoice_id', 'amount', 'method', 'notes');
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
        $same = strtoupper($p->client->currency) === LocalCurrency::code();
        $rate = $same ? '1' : $this->validate(["rowRate.{$paymentId}" => $this->rateRules()])['rowRate'][$paymentId];
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
    }

    public function render()
    {
        $this->authorize('manage-money');

        $client = $this->client_id ? Client::find($this->client_id) : null;
        $filtered = $this->clientFilter !== '' ? Client::find((int) $this->clientFilter) : null;
        $balanceClient = $filtered ?? $client;
        $like = $this->likeTerm();
        $local = LocalCurrency::code();
        $sameCurrency = $client && strtoupper($client->currency) === $local;
        $preview = null;
        if ($client && ! $sameCurrency && preg_match('/\A\d{1,7}(\.\d{1,2})?\z/', $this->amount) && Money::isValidRate($this->exchange_rate)) {
            $preview = Money::format(Money::convert(Money::toCents($this->amount), $this->exchange_rate), $local);
        }
        return view('livewire.payments.index', [
            'local' => $local, 'preview' => $preview, 'sameCurrency' => $sameCurrency,
            'clients'  => Client::orderBy('name')->get(['id', 'name']),
            'projects' => $client ? $client->projects()->orderBy('name')->get(['id', 'name']) : collect(),
            'invoices' => $client ? Invoice::where('client_id', $client->id)->where('status', '!=', 'void')->orderByDesc('id')->get(['id', 'number']) : collect(),
            'types'    => PaymentType::cases(),
            'payments' => $this->fitPage(Payment::with(['client', 'project', 'invoice'])
                ->when($filtered, fn ($q) => $q->where('client_id', $filtered->id))
                // client name or invoice number only; never the free-text notes
                ->when($like, fn ($q) => $q->where(fn ($w) => $w
                    ->whereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))
                    ->orWhereHas('invoice', fn ($i) => $i->whereRaw("number LIKE ? ESCAPE '!'", [$like]))))
                ->orderByDesc('paid_on')->orderByDesc('id'), 15),
            'balanceClient' => $balanceClient,
            'balance'  => $balanceClient ? app(ClientAccount::class)->balanceCents($balanceClient) : null,
        ]);
    }
}

<?php

namespace App\Livewire\Invoices;

use App\Livewire\Concerns\BuildsInvoiceItems;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Services\BillableService;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Create extends Component
{
    use BuildsInvoiceItems;

    #[Locked]
    public ?int $clientId = null;

    public string $taxPercent = '0';
    public string $bankAccountId = '';
    public string $exchangeRate = '';
    public string $currency = '';
    public string $issuedOn = '';

    #[Url(as: 'client', except: '')]
    public string $pick = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
        $this->issuedOn = today()->toDateString();
        $client = ctype_digit($this->pick) ? Client::find((int) $this->pick) : null;
        $client ? $this->loadClient($client) : $this->pick = '';
    }

    protected function itemsClient(): Client
    {
        return Client::findOrFail($this->clientId);
    }

    /** Switching client on the same page: pull that client's pending work, currency and rate; typed custom lines stay. */
    private function loadClient(Client $client): void
    {
        $this->clientId = $client->id;
        app(BillableService::class)->syncCompletedWork($client);
        app(BillableService::class)->syncRecurring($client);
        $this->currency = strtoupper($client->currency);
        $this->exchangeRate = self::tidy(LocalCurrency::rateFor($client->currency));
        $this->groups = $this->parkedGroups = [];
        $this->resetPicks();
        $this->resetValidation();
        $this->pickFirstTab($client);
    }

    public function updatedPick(): void
    {
        if ($this->pick === '') {
            $this->authorize('manage-money');
            $this->clientId = null;
            $this->groups = $this->parkedGroups = [];
            $this->resetPicks();

            return;
        }
        $this->choose();
    }

    /** 83.500000 -> 83.5, 1 stays 1, null -> empty. */
    private static function tidy(?string $r): string
    {
        return $r === null ? '' : (str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r);
    }

    public function updatedCurrency(): void
    {
        $this->exchangeRate = $this->currency !== '' ? self::tidy(LocalCurrency::rateFor($this->currency)) : '';
    }

    /** Every active currency, plus the local one and the client's own. */
    private function currencyOptions(?Client $client): array
    {
        return collect([LocalCurrency::code(), $client ? strtoupper($client->currency) : null])
            ->merge(\App\Models\Currency::active()->pluck('code'))->filter()->unique()->sort()->values()->all();
    }

    public function choose(): void
    {
        $this->authorize('manage-money');
        $this->validate(['pick' => ['required', 'regex:/^\d{1,18}$/', Rule::exists('clients', 'id')]], ['pick.required' => 'Choose a client.']);
        $this->loadClient(Client::findOrFail((int) $this->pick));
    }

    /** Re-syncs the client's billable work and reloads the picker for the chosen date; invoice items stay. */
    public function refreshWork(): void
    {
        $this->authorize('manage-money');
        $client = Client::findOrFail($this->clientId);
        app(BillableService::class)->syncCompletedWork($client);
        app(BillableService::class)->syncRecurring($client);
        $this->resetPicks();
        $this->pickFirstTab($client);
    }

    public function saveDraft(InvoiceService $svc)
    {
        return $this->persist($svc, true);
    }

    public function save(InvoiceService $svc)
    {
        return $this->persist($svc, false);
    }

    private function persist(InvoiceService $svc, bool $draft)
    {
        $this->authorize('manage-money');
        $client = Client::findOrFail($this->clientId);
        $this->flattenQuantities();

        $cur = $this->invoiceCurrency($client);
        $same = $cur === LocalCurrency::code();
        $positive = fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('Must be greater than zero.');
        $this->validate($this->itemRules() + [
            'currency' => ['nullable', Rule::in($this->currencyOptions($client))],
            'issuedOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'taxPercent' => ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) <= 10000 || $fail('Tax must be between 0 and 100.')],
            'bankAccountId' => ['nullable', 'regex:/^\d{1,18}$/', Rule::exists('bank_accounts', 'id')],
            'exchangeRate' => $same ? 'nullable' : ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')],
            'custom.*.description' => ['bail', 'required', 'string', 'max:255'],
            'custom.*.quantity' => ['bail', 'required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', $positive],
            'custom.*.unit_price' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', $positive],
        ], ['groups.*.label.required' => 'Give this item a label.']);

        [$lineIds, $labels] = $this->groupLines();
        $invoice = $svc->create(
            $client,
            $lineIds,
            array_values($this->custom),
            (float) $this->taxPercent,
            $this->bankAccountId === '' ? null : (int) $this->bankAccountId,
            $same ? null : $this->exchangeRate,
            $cur,
            $draft,
            $this->issuedOn,
            $labels,
            $this->showQuantity,
            $this->groupAmounts(),
        );

        return $this->redirectRoute('invoices.show', $invoice);
    }

    private function invoiceCurrency(Client $client): string
    {
        return $this->currency !== '' ? strtoupper($this->currency) : strtoupper($client->currency);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $client = $this->clientId ? Client::find($this->clientId) : null;
        $cur = $client ? $this->invoiceCurrency($client) : null;
        $rate = $cur === null ? null : ($cur === LocalCurrency::code() ? '1' : (Money::isValidRate($this->exchangeRate) ? $this->exchangeRate : LocalCurrency::rateFor($cur)));

        return view('livewire.invoices.create', [
            'summary' => $client ? $this->itemsSummary($client, $cur, $rate) : null,
            'picker' => $client ? $this->picker($client) : null,
            'client' => $client,
            'local' => LocalCurrency::code(),
            'sameCurrency' => $cur === LocalCurrency::code(),
            'currencies' => $client ? $this->currencyOptions($client) : [],
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'banks' => BankAccount::orderBy('label')->get(['id', 'label', 'bank_name']),
        ]);
    }
}

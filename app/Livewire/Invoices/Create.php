<?php

namespace App\Livewire\Invoices;

use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Services\BillableService;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Create extends Component
{
    #[Locked]
    public ?int $clientId = null;

    /** @var array<int, string> ticked pending line ids */
    public array $selected = [];

    /** @var array<int, array{description:string, quantity:string, unit_price:string}> */
    public array $custom = [];

    public string $taxPercent = '0';
    public string $bankAccountId = '';
    public string $exchangeRate = '';
    public string $currency = '';
    public string $pick = '';

    public function mount(BillableService $billable): void
    {
        $this->authorize('manage-money');
        $id = request()->query('client');
        $client = is_scalar($id) ? Client::find((int) $id) : null;
        if ($client) {
            $this->clientId = $client->id;
            $billable->syncCompletedWork($client);
            $this->currency = strtoupper($client->currency);
            $this->exchangeRate = self::tidy(LocalCurrency::rateFor($client->currency));
            $this->selected = $billable->pendingFor($client)->pluck('id')->map(fn ($i) => (string) $i)->all();
        }
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
        $this->validate(['pick' => ['required', Rule::exists('clients', 'id')]], ['pick.required' => 'Choose a client.']);
        $this->redirectRoute('invoices.create', ['client' => (int) $this->pick]);
    }

    public function addCustom(): void
    {
        $this->authorize('manage-money');
        $this->custom[] = ['description' => '', 'quantity' => '1', 'unit_price' => ''];
    }

    public function removeCustom(int $i): void
    {
        $this->authorize('manage-money');
        unset($this->custom[$i]);
        $this->custom = array_values($this->custom);
    }

    public function discardLine(int $lineId): void
    {
        $this->authorize('manage-money');
        // task-sourced lines re-sync from tasks, so only custom/recurring lines of this client may be dropped
        BillableLine::where('client_id', $this->clientId)->whereNull('invoice_id')->whereNull('source_type')->findOrFail($lineId)->delete();
        $this->selected = array_values(array_diff($this->selected, [(string) $lineId]));
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

        $cur = $this->currency !== '' ? strtoupper($this->currency) : strtoupper($client->currency);
        $same = $cur === LocalCurrency::code();
        $positive = fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('Must be greater than zero.');
        $this->validate([
            'currency' => ['nullable', Rule::in($this->currencyOptions($client))],
            'selected' => 'array|max:500',
            'custom' => 'array|max:50',
            'selected.*' => ['bail', 'regex:/^\d{1,18}$/'],
            'taxPercent' => ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) <= 10000 || $fail('Tax must be between 0 and 100.')],
            'bankAccountId' => ['nullable', 'regex:/^\d{1,18}$/', Rule::exists('bank_accounts', 'id')],
            'exchangeRate' => $same ? 'nullable' : ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')],
            'custom.*.description' => ['bail', 'required', 'string', 'max:255'],
            'custom.*.quantity' => ['bail', 'required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', $positive],
            'custom.*.unit_price' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', $positive],
        ]);

        $invoice = $svc->create(
            $client,
            array_map('intval', $this->selected),
            array_values($this->custom),
            (float) $this->taxPercent,
            $this->bankAccountId === '' ? null : (int) $this->bankAccountId,
            $same ? null : $this->exchangeRate,
            $cur,
            $draft,
        );

        return $this->redirectRoute('invoices.show', $invoice);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $client = $this->clientId ? Client::find($this->clientId) : null;

        return view('livewire.invoices.create', [
            'client' => $client,
            'local' => LocalCurrency::code(),
            'sameCurrency' => $client && ($this->currency !== '' ? strtoupper($this->currency) : strtoupper($client->currency)) === LocalCurrency::code(),
            'currencies' => $client ? $this->currencyOptions($client) : [],
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'lines' => $client ? app(BillableService::class)->pendingFor($client) : collect(),
            'banks' => BankAccount::orderBy('label')->get(['id', 'label', 'bank_name']),
        ]);
    }
}

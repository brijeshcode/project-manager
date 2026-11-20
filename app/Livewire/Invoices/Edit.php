<?php

namespace App\Livewire\Invoices;

use App\Livewire\Concerns\BuildsInvoiceItems;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\BillableService;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Edit extends Component
{
    use BuildsInvoiceItems;

    #[Locked]
    public int $invoiceId;

    /** @var array<int, array{description:string, quantity:string, unit_price:string}> manual lines on this invoice, by line id */
    public array $edits = [];

    public string $taxPercent = '0';
    public string $bankAccountId = '';
    public string $exchangeRate = '';
    public string $issuedOn = '';
    public string $forMonth = '';
    public string $dueOn = '';
    public string $terms = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorize('manage-money');
        abort_if($invoice->status === 'void', 403, 'A cancelled invoice cannot be edited.');
        $this->invoiceId = $invoice->id;
        $this->taxPercent = (string) $invoice->tax_percent;
        $this->bankAccountId = (string) ($invoice->bank_account_id ?? '');
        $this->exchangeRate = $invoice->exchange_rate === null ? '' : (str_contains($r = (string) $invoice->exchange_rate, '.') ? rtrim(rtrim($r, '0'), '.') : $r);
        $this->issuedOn = $invoice->issued_on->toDateString();
        $this->forMonth = (string) $invoice->for_month;
        $this->dueOn = $invoice->due_on?->toDateString() ?? '';
        $this->terms = (string) $invoice->terms;
        $this->showQuantity = (bool) $invoice->show_quantity;

        // own manual lines stay editable rows; task / recurring lines become items, one per printed label
        $own = BillableLine::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $manual = $own->whereNull('source_type')->whereNull('recurring_charge_id')->whereNull('invoice_label');
        $this->edits = $manual->mapWithKeys(fn ($l) => [$l->id => [
            'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => Money::toInput((int) $l->unit_price_cents),
        ]])->all();
        $this->updatedShowQuantity();
        $this->groups = $own->diff($manual)->groupBy(fn ($l) => $l->recurring_charge_id ? 'r:'.$l->id : ($l->invoice_label ?? 'line:'.$l->id))
            ->map(fn ($g) => $g->first()->recurring_charge_id ? self::recurringGroup($g->first(), $g->first()->invoice_label)
                : ['label' => $g->first()->invoice_label ?? $g->first()->description, 'lines' => $g->pluck('id')->map(fn ($i) => (string) $i)->all()])
            ->values()->all();

        app(BillableService::class)->syncCompletedWork($invoice->client);
        app(BillableService::class)->syncRecurring($invoice->client);
        $this->pickFirstTab($invoice->client);
    }

    protected function itemsClient(): Client
    {
        return Invoice::findOrFail($this->invoiceId)->client;
    }

    /** An existing manual line moves to "Set aside" as an item; put back, it prints its saved text and amount. */
    public function removeEdit(int $lineId): void
    {
        $this->authorize('manage-money');
        if (isset($this->edits[$lineId])) {
            $this->parkedGroups[] = ['label' => $this->edits[$lineId]['description'] ?: 'Item', 'lines' => [(string) $lineId]];
            unset($this->edits[$lineId]);
        }
    }

    public function save(InvoiceService $svc)
    {
        $this->authorize('manage-money');
        $invoice = Invoice::with('client')->findOrFail($this->invoiceId);
        $this->flattenQuantities();
        $same = strtoupper($invoice->currency) === LocalCurrency::code();
        $positive = fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('Must be greater than zero.');
        $this->validate($this->itemRules() + [
            'taxPercent' => ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) <= 10000 || $fail('Tax must be between 0 and 100.')],
            'bankAccountId' => ['nullable', 'regex:/^\d{1,18}$/', Rule::exists('bank_accounts', 'id')],
            'exchangeRate' => $same ? 'nullable' : ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')],
            'issuedOn' => ['required', 'date_format:Y-m-d'],
            'forMonth' => ['nullable', 'date_format:Y-m'],
            'dueOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issuedOn'],
            'terms' => 'nullable|string|max:255',
            'edits.*.description' => ['bail', 'required', 'string', 'max:255'],
            'edits.*.quantity' => ['bail', 'required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', $positive],
            'edits.*.unit_price' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', $positive],
            'custom.*.description' => ['bail', 'required', 'string', 'max:255'],
            'custom.*.quantity' => ['bail', 'required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', $positive],
            'custom.*.unit_price' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', $positive],
        ], ['groups.*.label.required' => 'Give this item a label.']);

        [$lineIds, $labels] = $this->groupLines();
        $svc->update($invoice, [...$lineIds, ...array_map('intval', array_keys($this->edits))], array_values($this->custom), [
            'tax_percent' => $this->taxPercent, 'issued_on' => $this->issuedOn, 'for_month' => $this->forMonth ?: null, 'due_on' => $this->dueOn ?: null,
            'terms' => $this->terms ?: null, 'show_quantity' => $this->showQuantity, 'bank_account_id' => $this->bankAccountId === '' ? null : (int) $this->bankAccountId,
            'exchange_rate' => $same ? null : $this->exchangeRate,
        ], $this->edits, $labels, $this->groupAmounts());
        session()->flash('toast', 'Invoice updated.');

        return $this->redirectRoute('invoices.show', $invoice);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $invoice = Invoice::with('client')->findOrFail($this->invoiceId);
        $cur = strtoupper($invoice->currency);
        $rate = $cur === LocalCurrency::code() ? '1' : (Money::isValidRate($this->exchangeRate) ? $this->exchangeRate : ($invoice->exchange_rate !== null ? (string) $invoice->exchange_rate : LocalCurrency::rateFor($cur)));
        $edits = array_map(fn ($r) => $this->rowAmount($r), $this->edits);

        return view('livewire.invoices.edit', [
            'summary' => ['edits' => $edits] + $this->itemsSummary($invoice->client, $cur, $rate, array_sum($edits), count($edits)),
            'picker' => $this->picker($invoice->client),
            'invoice' => $invoice,
            'client' => $invoice->client,
            'local' => LocalCurrency::code(),
            'sameCurrency' => $cur === LocalCurrency::code(),
            'banks' => BankAccount::orderBy('label')->get(['id', 'label', 'bank_name']),
        ]);
    }
}

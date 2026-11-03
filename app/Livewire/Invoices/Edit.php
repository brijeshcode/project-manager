<?php

namespace App\Livewire\Invoices;

use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Edit extends Component
{
    #[Locked]
    public int $invoiceId;

    /** @var array<int, string> ticked line ids: this invoice's task/recurring lines + pending lines */
    public array $selected = [];

    /** @var array<int, array{description:string, quantity:string, unit_price:string}> new lines */
    public array $custom = [];

    /** @var array<int, array{description:string, quantity:string, unit_price:string}> manual lines on this invoice, by line id */
    public array $edits = [];

    public string $taxPercent = '0';
    public string $bankAccountId = '';
    public string $exchangeRate = '';
    public string $issuedOn = '';
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
        $this->dueOn = $invoice->due_on?->toDateString() ?? '';
        $this->terms = (string) $invoice->terms;
        $own = BillableLine::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $this->selected = $own->pluck('id')->map(fn ($i) => (string) $i)->all();
        $this->edits = $own->whereNull('source_type')->mapWithKeys(fn ($l) => [$l->id => [
            'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => Money::toInput((int) $l->unit_price_cents),
        ]])->all();
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

    public function save(InvoiceService $svc)
    {
        $this->authorize('manage-money');
        $invoice = Invoice::with('client')->findOrFail($this->invoiceId);
        $same = strtoupper($invoice->currency) === LocalCurrency::code();
        $positive = fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('Must be greater than zero.');
        $this->validate([
            'selected' => 'array|max:500',
            'custom' => 'array|max:50',
            'selected.*' => ['bail', 'regex:/^\d{1,18}$/'],
            'taxPercent' => ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) <= 10000 || $fail('Tax must be between 0 and 100.')],
            'bankAccountId' => ['nullable', 'regex:/^\d{1,18}$/', Rule::exists('bank_accounts', 'id')],
            'exchangeRate' => $same ? 'nullable' : ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')],
            'issuedOn' => ['required', 'date_format:Y-m-d'],
            'dueOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issuedOn'],
            'terms' => 'nullable|string|max:255',
            'edits.*.description' => ['bail', 'required', 'string', 'max:255'],
            'edits.*.quantity' => ['bail', 'required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', $positive],
            'edits.*.unit_price' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', $positive],
            'custom.*.description' => ['bail', 'required', 'string', 'max:255'],
            'custom.*.quantity' => ['bail', 'required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', $positive],
            'custom.*.unit_price' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', $positive],
        ]);

        $svc->update($invoice, array_map('intval', $this->selected), array_values($this->custom), [
            'tax_percent' => $this->taxPercent, 'issued_on' => $this->issuedOn, 'due_on' => $this->dueOn ?: null,
            'terms' => $this->terms ?: null, 'bank_account_id' => $this->bankAccountId === '' ? null : (int) $this->bankAccountId,
            'exchange_rate' => $same ? null : $this->exchangeRate,
        ], $this->edits);
        session()->flash('toast', 'Invoice updated.');

        return $this->redirectRoute('invoices.show', $invoice);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $invoice = Invoice::with('client')->findOrFail($this->invoiceId);
        $ownLines = BillableLine::where('invoice_id', $invoice->id)->orderBy('id')->get();

        return view('livewire.invoices.edit', [
            'invoice' => $invoice,
            'client' => $invoice->client,
            'local' => LocalCurrency::code(),
            'sameCurrency' => strtoupper($invoice->currency) === LocalCurrency::code(),
            'lines' => $ownLines->concat(BillableLine::pending()->where('client_id', $invoice->client_id)->orderBy('id')->get()),
            'banks' => BankAccount::orderBy('label')->get(['id', 'label', 'bank_name']),
        ]);
    }
}

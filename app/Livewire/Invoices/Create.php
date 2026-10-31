<?php

namespace App\Livewire\Invoices;

use App\Models\BankAccount;
use App\Models\Client;
use App\Services\BillableService;
use App\Services\InvoiceService;
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
    public string $inrRate = '';
    public string $pick = '';

    public function mount(BillableService $billable): void
    {
        $this->authorize('manage-money');
        $id = request()->query('client');
        $client = is_scalar($id) ? Client::find((int) $id) : null;
        if ($client) {
            $this->clientId = $client->id;
            $billable->syncCompletedWork($client);
            $this->selected = $billable->pendingFor($client)->pluck('id')->map(fn ($i) => (string) $i)->all();
        }
    }

    public function choose(): void
    {
        $this->authorize('manage-money');
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

    public function save(InvoiceService $svc)
    {
        $this->authorize('manage-money');
        $client = Client::findOrFail($this->clientId);

        $positive = fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('Must be greater than zero.');
        $this->validate([
            'selected' => 'array',
            'selected.*' => ['bail', 'regex:/^\d{1,18}$/'],
            'taxPercent' => ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) <= 10000 || $fail('Tax must be between 0 and 100.')],
            'bankAccountId' => ['nullable', 'regex:/^\d{1,18}$/', Rule::exists('bank_accounts', 'id')],
            'inrRate' => ['bail', 'nullable', 'regex:/^\d{1,6}(\.\d{1,4})?$/', fn ($a, $v, $fail) => (float) $v > 0 || $fail('Must be greater than zero.')],
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
            $this->inrRate === '' ? null : (float) $this->inrRate,
        );

        return $this->redirectRoute('invoices.show', $invoice);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $client = $this->clientId ? Client::find($this->clientId) : null;

        return view('livewire.invoices.create', [
            'client' => $client,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'lines' => $client ? app(BillableService::class)->pendingFor($client) : collect(),
            'banks' => BankAccount::orderBy('label')->get(['id', 'label', 'bank_name']),
        ]);
    }
}

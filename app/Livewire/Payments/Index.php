<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ClientAccount;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?int $invoice_id = null;
    public string $amount = '';
    public string $paid_on = '';
    public string $type = 'project';
    public string $method = '';
    public string $notes = '';
    public string $clientFilter = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
        $this->paid_on = today()->toDateString();
    }

    public function updatedClientId(): void
    {
        $this->reset('project_id', 'invoice_id');
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $this->authorize('manage-money');

        $data = $this->validate([
            'client_id'  => 'required|exists:clients,id',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('client_id', $this->client_id)],
            'invoice_id' => ['nullable', Rule::exists('invoices', 'id')->where('client_id', $this->client_id)->where(fn ($q) => $q->where('status', 'sent'))],
            'amount'     => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.')],
            'paid_on'    => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'type'       => ['required', new Enum(PaymentType::class)],
            'method'     => 'nullable|string|max:100',
            'notes'      => 'nullable|string|max:500',
        ]);

        Payment::create([
            'client_id' => $data['client_id'], 'project_id' => $data['project_id'] ?: null,
            'invoice_id' => $data['invoice_id'] ?: null, 'amount_cents' => Money::toCents($data['amount']),
            'paid_on' => $data['paid_on'], 'type' => $data['type'],
            'method' => $data['method'] ?: null, 'notes' => $data['notes'] ?: null,
        ]);

        $this->reset('project_id', 'invoice_id', 'amount', 'method', 'notes');
        $this->resetPage();
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

        return view('livewire.payments.index', [
            'clients'  => Client::orderBy('name')->get(['id', 'name']),
            'projects' => $client ? $client->projects()->orderBy('name')->get(['id', 'name']) : collect(),
            'invoices' => $client ? Invoice::where('client_id', $client->id)->where('status', '!=', 'void')->orderByDesc('id')->get(['id', 'number']) : collect(),
            'types'    => PaymentType::cases(),
            'payments' => Payment::with(['client', 'project', 'invoice'])
                ->when($filtered, fn ($q) => $q->where('client_id', $filtered->id))
                ->orderByDesc('paid_on')->orderByDesc('id')->paginate(20),
            'balanceClient' => $balanceClient,
            'balance'  => $balanceClient ? app(ClientAccount::class)->balanceCents($balanceClient) : null,
        ]);
    }
}

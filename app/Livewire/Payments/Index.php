<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDocument;
use App\Services\ClientAccount;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Livewire\Concerns\PaymentFields;
use App\Livewire\Concerns\WithListSearch;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithListSearch, WithFileUploads, PaymentFields;

    public string $clientFilter = '';
    /** @var array<int,string> legacy rows: typed rate per payment id */
    public array $rowRate = [];
    /** @var array<int,mixed> uploads on the record form */
    public array $newDocs = [];
    /** @var array<int,array<int,mixed>> uploads per payment row */
    public array $rowDocs = [];

    public const DOC_RULE = 'file|mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx|max:10240';

    private function storeDocs(Payment $p, array $files): void
    {
        foreach ($files as $f) {
            $name = basename(str_replace(chr(92), '/', $f->getClientOriginalName()));
            $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $name), 0, 255);
            $p->documents()->create(['path' => $f->store('payment-documents', 'local'), 'original_name' => $name !== '' ? $name : 'file', 'uploaded_by' => auth()->id()]);
        }
    }

    public function addDocument(int $paymentId): void
    {
        $this->authorize('manage-money');
        $p = Payment::findOrFail($paymentId);
        $this->validate(["rowDocs.{$paymentId}" => 'required|array|min:1|max:5', "rowDocs.{$paymentId}.*" => self::DOC_RULE]);
        $this->storeDocs($p, $this->rowDocs[$paymentId]);
        unset($this->rowDocs[$paymentId]);
        $this->dispatch('toast', message: 'Document added.');
    }

    public function removeDocument(int $documentId): void
    {
        $this->authorize('manage-money');
        $d = PaymentDocument::findOrFail($documentId);
        $d->delete();
        Log::info('payment document removed', ['payment_id' => $d->payment_id, 'name' => $d->original_name, 'user_id' => auth()->id()]);
        $this->dispatch('toast', message: 'Document removed.');
    }

    public function mount(): void
    {
        $this->authorize('manage-money');
        $this->paid_on = today()->toDateString();
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $this->authorize('manage-money');
        $this->validate(['newDocs' => 'array|max:5', 'newDocs.*' => self::DOC_RULE]);
        $payment = Payment::create($this->validatedPayment());

        $this->storeDocs($payment, $this->newDocs);
        $this->reset('project_id', 'amount', 'method', 'notes', 'newDocs');
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
        $formCurrency = $this->effectiveCurrency($client);
        return view('livewire.payments.index', [
            'local' => $local, 'preview' => $this->paymentPreview($client), 'formCurrency' => $formCurrency,
            'needsRate' => $client && $formCurrency !== $local, 'currencies' => $this->currencyOptions($client),
            'stats' => \App\Support\MoneyStats::payments(),
            'clients'  => Client::orderBy('name')->get(['id', 'name']),
            'projects' => $client ? $client->projects()->orderBy('name')->get(['id', 'name']) : collect(),
            'invoices' => $client ? Invoice::where('client_id', $client->id)->where('status', '!=', 'void')->orderByDesc('id')->get(['id', 'number']) : collect(),
            'types'    => PaymentType::cases(),
            'payments' => $this->fitPage(Payment::with(['client', 'project', 'documents'])
                ->when($filtered, fn ($q) => $q->where('client_id', $filtered->id))
                // client name only; never the free-text notes
                ->when($like, fn ($q) => $q->whereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like])))
                ->orderByDesc('paid_on')->orderByDesc('id'), \App\Support\Settings::pageSize()),
            'balanceClient' => $balanceClient,
            'balance'  => $balanceClient ? app(ClientAccount::class)->balanceCents($balanceClient) : null,
            'balanceLocal' => $balanceClient ? app(ClientAccount::class)->localBalanceCents($balanceClient) : null,
        ]);
    }
}

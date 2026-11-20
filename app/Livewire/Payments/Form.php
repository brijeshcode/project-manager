<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentType;
use App\Livewire\Concerns\PaymentFields;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentDocument;
use App\Services\ClientAccount;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Record a payment (create) or change one (edit). */
class Form extends Component
{
    use PaymentFields, WithFileUploads;

    public const DOC_RULE = 'file|mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx|max:10240';

    #[Locked]
    public ?int $paymentId = null;

    /** @var array<int,mixed> */
    public array $newDocs = [];

    public function mount(?Payment $payment = null): void
    {
        $this->authorize('manage-money');
        if (! $payment?->exists) {
            $this->paid_on = today()->toDateString();
            $id = request()->query('client');
            if (is_scalar($id) && Client::whereKey((int) $id)->exists()) {
                $this->client_id = (int) $id;
                $this->updatedClientId();
            }

            return;
        }
        $this->paymentId = $payment->id;
        $this->client_id = $payment->client_id;
        $this->project_id = $payment->project_id;
        $this->currency = $payment->currency;
        $this->amount = Money::toInput($payment->amount_cents);
        $this->paid_on = $payment->paid_on->toDateString();
        $this->for_month = (string) $payment->for_month;
        $this->type = $payment->type->value;
        $this->method = (string) $payment->method;
        $this->notes = (string) $payment->notes;
        $this->documents_visible_to_client = (bool) $payment->documents_visible_to_client;
        $this->exchange_rate = self::tidy($payment->exchange_rate === null ? LocalCurrency::rateFor($this->currency) : (string) $payment->exchange_rate);
    }

    public function save()
    {
        $this->authorize('manage-money');
        $this->validate(['newDocs' => 'array|max:5', 'newDocs.*' => self::DOC_RULE]);
        $data = $this->validatedPayment();
        if ($this->paymentId) {
            $payment = Payment::findOrFail($this->paymentId);
            $payment->update($data);
            Log::info('payment edited', ['id' => $payment->id, 'client_id' => $payment->client_id, 'amount_cents' => $payment->amount_cents, 'currency' => $payment->currency, 'user_id' => auth()->id()]);
        } else {
            $payment = Payment::create($data);
        }
        foreach ($this->newDocs as $f) {
            $name = basename(str_replace(chr(92), '/', $f->getClientOriginalName()));
            $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $name), 0, 255);
            $payment->documents()->create(['path' => $f->store('payment-documents', 'local'), 'original_name' => $name !== '' ? $name : 'file', 'uploaded_by' => auth()->id()]);
        }
        session()->flash('toast', $this->paymentId ? 'Payment updated.' : 'Payment recorded.');

        return $this->redirectRoute('payments.show', $payment);
    }

    public function removeDocument(int $documentId): void
    {
        $this->authorize('manage-money');
        $d = PaymentDocument::where('payment_id', $this->paymentId)->findOrFail($documentId);
        $d->delete();
        Log::info('payment document removed', ['payment_id' => $d->payment_id, 'name' => $d->original_name, 'user_id' => auth()->id()]);
        $this->dispatch('toast', message: 'Document removed.');
    }

    public function render()
    {
        $this->authorize('manage-money');
        $client = $this->client_id ? Client::find($this->client_id) : null;
        $cur = $this->effectiveCurrency($client);
        $acct = app(ClientAccount::class);

        return view('livewire.payments.form', [
            'local' => LocalCurrency::code(), 'preview' => $this->paymentPreview($client), 'formCurrency' => $cur,
            'needsRate' => $client && $cur !== LocalCurrency::code(), 'currencies' => $this->currencyOptions($client),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => $client ? $client->projects()->orderBy('name')->get(['id', 'name']) : collect(),
            'types' => PaymentType::cases(),
            'documents' => $this->paymentId ? PaymentDocument::where('payment_id', $this->paymentId)->orderBy('id')->get() : collect(),
            'client' => $client,
            'balance' => $client ? $acct->balanceCents($client) : null,
            'balanceLocal' => $client ? $acct->localBalanceCents($client) : null,
        ]);
    }
}

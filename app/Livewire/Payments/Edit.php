<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentType;
use App\Livewire\Concerns\PaymentFields;
use App\Models\Client;
use App\Models\Payment;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Edit extends Component
{
    use PaymentFields;

    #[Locked]
    public int $paymentId;

    public function mount(Payment $payment): void
    {
        $this->authorize('manage-money');
        $payment->load('client');
        $this->paymentId = $payment->id;
        $this->client_id = $payment->client_id;
        $this->project_id = $payment->project_id;
        $this->currency = $payment->currency;
        $this->amount = Money::toInput($payment->amount_cents);
        $this->paid_on = $payment->paid_on->toDateString();
        $this->type = $payment->type->value;
        $this->method = (string) $payment->method;
        $this->notes = (string) $payment->notes;
        $this->exchange_rate = self::tidy($payment->exchange_rate === null ? LocalCurrency::rateFor($this->currency) : (string) $payment->exchange_rate);
    }

    public function save()
    {
        $this->authorize('manage-money');
        $payment = Payment::findOrFail($this->paymentId);
        $payment->update($this->validatedPayment());
        Log::info('payment edited', ['id' => $payment->id, 'client_id' => $payment->client_id, 'amount_cents' => $payment->amount_cents, 'currency' => $payment->currency, 'user_id' => auth()->id()]);
        session()->flash('toast', 'Payment updated.');

        return $this->redirectRoute('payments.show', $payment);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $client = $this->client_id ? Client::find($this->client_id) : null;
        $cur = $this->effectiveCurrency($client);

        return view('livewire.payments.edit', [
            'local' => LocalCurrency::code(), 'preview' => $this->paymentPreview($client), 'formCurrency' => $cur,
            'needsRate' => $client && $cur !== LocalCurrency::code(), 'currencies' => $this->currencyOptions($client),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => $client ? $client->projects()->orderBy('name')->get(['id', 'name']) : collect(),
            'types' => PaymentType::cases(),
        ]);
    }
}

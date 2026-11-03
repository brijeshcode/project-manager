<?php

namespace App\Livewire\Payments;

use App\Models\Payment;
use App\Services\LocalCurrency;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $paymentId;

    public function mount(Payment $payment): void
    {
        $this->authorize('manage-money');
        $this->paymentId = $payment->id;
    }

    public function delete()
    {
        $this->authorize('manage-money');
        $p = Payment::findOrFail($this->paymentId);
        $p->delete();
        // audit trail only: ids and amounts, never the free-text notes
        Log::info('payment deleted', ['id' => $p->id, 'client_id' => $p->client_id, 'amount_cents' => $p->amount_cents, 'paid_on' => $p->paid_on->toDateString(), 'user_id' => auth()->id()]);
        session()->flash('toast', 'Payment deleted.');

        return $this->redirectRoute('payments.index');
    }

    public function render()
    {
        $this->authorize('manage-money');

        return view('livewire.payments.show', [
            'payment' => Payment::with(['client', 'project', 'documents'])->findOrFail($this->paymentId),
            'local' => LocalCurrency::code(),
        ]);
    }
}

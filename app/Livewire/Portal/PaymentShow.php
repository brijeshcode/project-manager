<?php

namespace App\Livewire\Portal;

use App\Models\Payment;
use App\Models\PaymentDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PaymentShow extends Component
{
    #[Locked]
    public int $paymentId;

    public function mount(int $payment): void
    {
        $this->paymentId = $this->payment($payment)->id;
    }

    private function guard(): int
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
        abort_unless(auth()->user()->client->portalShows('payments'), 404);

        return auth()->user()->client_id;
    }

    /** Own client's payment; notes, rate and local amounts are never selected. */
    private function payment(int $id): Payment
    {
        $cid = $this->guard();

        return Payment::where('client_id', $cid)
            ->with([
                'project' => fn ($q) => $q->where('client_id', $cid)->select('id', 'name', 'client_id'),
                'documents' => fn ($q) => $q->select('id', 'payment_id', 'original_name'),
            ])
            ->findOrFail($id, ['id', 'client_id', 'project_id', 'currency_id', 'amount_cents', 'paid_on', 'type', 'method', 'documents_visible_to_client']);
    }

    /** Same guard as the payments list: own payment, documents shown to the client. */
    public function download(int $documentId)
    {
        $cid = $this->guard();
        $doc = PaymentDocument::whereKey($documentId)
            ->whereHas('payment', fn ($q) => $q->whereKey($this->paymentId)->where('client_id', $cid)->where('documents_visible_to_client', true))
            ->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($disk->exists($doc->path), 404);
        Log::info('payment document downloaded by client', ['document_id' => $doc->id, 'payment_id' => $doc->payment_id, 'user_id' => auth()->id()]);

        return $disk->download($doc->path, $doc->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function render()
    {
        $payment = $this->payment($this->paymentId);

        return view('livewire.portal.payment-show', [
            'payment' => $payment,
            'documents' => $payment->documents_visible_to_client ? $payment->documents : collect(),
        ])->layout('layouts.app');
    }
}

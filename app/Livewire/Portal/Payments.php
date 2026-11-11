<?php

namespace App\Livewire\Portal;

use App\Models\Payment;
use App\Models\PaymentDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithPagination;

class Payments extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->guard();
    }

    private function guard(): int
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);

        return auth()->user()->client_id;
    }

    /** Only the client's own payments, and only when the owner chose to show that payment's documents. */
    private static function visibleTo(int $cid): \Closure
    {
        return fn ($q) => $q->where('client_id', $cid)->where('documents_visible_to_client', true);
    }

    public function download(int $documentId)
    {
        $doc = PaymentDocument::whereKey($documentId)->whereHas('payment', self::visibleTo($this->guard()))->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($disk->exists($doc->path), 404);
        Log::info('payment document downloaded by client', ['document_id' => $doc->id, 'payment_id' => $doc->payment_id, 'user_id' => auth()->id()]);

        return $disk->download($doc->path, $doc->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function render()
    {
        // notes deliberately not selected
        $cid = $this->guard();
        $payments = Payment::where('client_id', $cid)
            ->with([
                'project' => fn ($q) => $q->where('client_id', $cid)->select('id', 'name', 'client_id'),
                'documents' => fn ($q) => $q->whereHas('payment', self::visibleTo($cid))->select('id', 'payment_id', 'original_name'),
            ])
            ->latest('paid_on')->latest('id')
            ->paginate(\App\Support\Settings::pageSize(), ['id', 'client_id', 'project_id', 'currency_id', 'amount_cents', 'paid_on', 'type', 'method', 'documents_visible_to_client']);

        return view('livewire.portal.payments', [
            'payments' => $payments,
            'currency' => auth()->user()->client->currency,
            'hasDocs' => $payments->contains(fn ($p) => $p->documents->isNotEmpty()),
        ])->layout('layouts.app');
    }
}

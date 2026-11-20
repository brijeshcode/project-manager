<?php

namespace App\Livewire\Portal;

use App\Enums\PaymentType;
use App\Livewire\Concerns\RemembersView;
use App\Livewire\Concerns\WithDateFilter;
use App\Livewire\Concerns\WithListSearch;
use App\Models\Payment;
use App\Models\PaymentDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;

class Payments extends Component
{
    use RemembersView;
    use WithListSearch, WithDateFilter;

    #[Url(as: 'view')]
    public string $view = 'list';

    #[Url]
    public string $typeFilter = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    private function guard(): int
    {
        abort_unless(auth()->user()->hasRole('client'), 403);
        abort_if(! auth()->user()->client_id, 403);
        abort_unless(auth()->user()->client->portalShows('payments'), 404);

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
        $this->view = in_array($this->view, ['cards', 'list'], true) ? $this->view : 'list';
        $cid = $this->guard();
        $type = PaymentType::tryFrom($this->typeFilter);
        $filtered = $this->applyDateFilter(Payment::where('client_id', $cid)->when($type, fn ($q) => $q->where('type', $type->value)), 'paid_on');
        // notes, rates and local amounts deliberately not selected; stats in each payment's own currency
        $all = (clone $filtered)->get(['id', 'client_id', 'currency_id', 'amount_cents', 'type']);
        $byCur = fn ($rows) => $rows->groupBy(fn ($p) => $p->currency)->map->sum('amount_cents')->all();
        $payments = $this->fitPage($filtered
            ->with([
                'project' => fn ($q) => $q->where('client_id', $cid)->select('id', 'name', 'client_id'),
                'documents' => fn ($q) => $q->whereHas('payment', self::visibleTo($cid))->select('id', 'payment_id', 'original_name'),
            ])
            ->latest('paid_on')->latest('id')
            ->select(['id', 'client_id', 'project_id', 'currency_id', 'amount_cents', 'paid_on', 'for_month', 'type', 'method', 'documents_visible_to_client']), \App\Support\Settings::pageSize());

        return view('livewire.portal.payments', [
            'payments' => $payments,
            'currency' => auth()->user()->client->currency,
            'hasDocs' => $payments->contains(fn ($p) => $p->documents->isNotEmpty()),
            'types' => PaymentType::cases(),
            'period' => $this->activePeriod(),
            'years' => $this->yearsSince(Payment::where('client_id', $cid)->min('paid_on')),
            'year' => $this->activeYear(),
            'stats' => [
                'total' => $byCur($all),
                'count' => $all->count(),
                'average' => $all->groupBy(fn ($p) => $p->currency)->map(fn ($rows) => intdiv((int) $rows->sum('amount_cents'), $rows->count()))->all(),
                'by_type' => $all->groupBy(fn ($p) => $p->type->value)->map($byCur)->all(),
            ],
        ])->layout('layouts.app');
    }
}

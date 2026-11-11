<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\DeletesClient;
use App\Models\Client;
use App\Models\Payment;
use App\Services\ClientAccount;
use App\Models\BillableLine;
use App\Services\LocalCurrency;
use App\Support\ClientStatement;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use DeletesClient;

    #[Locked]
    public int $clientId;

    public string $from = '';

    public string $to = '';

    public function mount(Client $client): void
    {
        $this->authorize('view', $client);
        $this->clientId = $client->id;
    }

    /** The argument is ignored: this page can only delete its own client. */
    public function delete(?int $clientId = null): void
    {
        if ($this->deleteClient($this->clientId)) {
            session()->flash('toast', 'Client deleted.');
            $this->redirect(route('clients.index'), navigate: true);
        }
    }

    /** Statement date shortcuts as [from, to]; financial year April–March like the invoices list. */
    private function periods(): array
    {
        $fy = today()->month >= 4 ? today()->setDate(today()->year, 4, 1) : today()->setDate(today()->year - 1, 4, 1);

        return array_map(fn ($r) => array_map(fn ($d) => $d->toDateString(), $r), [
            'month' => [today()->startOfMonth(), today()->endOfMonth()],
            'year' => [today()->startOfYear(), today()->endOfYear()],
            'fy' => [$fy, $fy->copy()->addYear()->subDay()],
        ]);
    }

    public function setPeriod(string $period): void
    {
        [$this->from, $this->to] = $this->periods()[$period] ?? ['', ''];
    }

    private function validDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** Ledger rows inside the date filter plus the balance carried in from before it. */
    private function statement(Client $client): array
    {
        $all = ClientStatement::rows($client);
        $from = $this->validDate($this->from) ? $this->from : null;
        $to = $this->validDate($this->to) ? $this->to : null;
        $day = fn ($r) => (string) $r['date']?->toDateString();
        $before = $from ? $all->filter(fn ($r) => $day($r) < $from) : collect();
        $rows = $all->filter(fn ($r) => (! $from || $day($r) >= $from) && (! $to || $day($r) <= $to))->values();
        $opening = $before->isEmpty() ? 0 : $before->last()['balance'];

        return [
            'rows' => $rows,
            'opening' => $opening,
            'debit' => $rows->where('type', 'invoice')->sum('amount'),
            'credit' => $rows->where('type', 'payment')->sum('amount'),
            'closing' => $rows->isEmpty() ? $opening : $rows->last()['balance'],
            'period' => array_search([$this->from, $this->to], $this->periods(), true),
        ];
    }

    /** Recurring charges with how often and how much they have been put on non-cancelled invoices. */
    private function subscriptions(Client $client)
    {
        $charges = $client->recurringCharges()->with('project')->orderByDesc('active')->orderBy('next_due_on')->get();
        $billed = BillableLine::whereIn('recurring_charge_id', $charges->pluck('id'))
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'void'))
            ->groupBy('recurring_charge_id')->select('recurring_charge_id', DB::raw('count(*) as n'), DB::raw('sum(amount_cents) as s'))
            ->get()->keyBy('recurring_charge_id');

        return $charges->each(function ($c) use ($billed, $client) {
            $c->times_invoiced = (int) ($billed[$c->id]->n ?? 0);
            $c->total_invoiced = (int) ($billed[$c->id]->s ?? 0);
            $c->display_currency = $c->project?->currency ?? $client->currency;
        });
    }

    public function render()
    {
        $client = Client::findOrFail($this->clientId);
        $this->authorize('view', $client);
        $t = app(ClientAccount::class)->totals([$client->id])[$client->id];

        $code = LocalCurrency::code();
        $invoices = $client->invoices()->latest('issued_on')->latest('id')->get();

        // Local lines: hidden for a same-currency client; with any missing rate a note replaces the (otherwise partial) total.
        $billedLocal = $receivedLocal = null;
        $billedMissing = $receivedMissing = 0;
        if (strtoupper($client->currency) !== $code) {
            $sent = $invoices->where('status', 'sent');
            $billedMissing = $sent->whereNull('exchange_rate')->count();
            $billedLocal = $sent->sum(fn ($i) => $i->localTotalCents() ?? 0);
            $r = Payment::where('client_id', $client->id)
                ->selectRaw('COALESCE(SUM(CASE WHEN local_currency = ? THEN local_amount_cents ELSE 0 END), 0) as l, COALESCE(SUM(CASE WHEN local_amount_cents IS NULL OR local_currency <> ? THEN 1 ELSE 0 END), 0) as m', [$code, $code])->first();
            $receivedLocal = (int) $r->l;
            $receivedMissing = (int) $r->m;
        }

        return view('livewire.clients.show', [
            'client' => $client,
            'localCode' => $code,
            'billedLocal' => $billedLocal,
            'receivedLocal' => $receivedLocal,
            'billedMissing' => $billedMissing,
            'receivedMissing' => $receivedMissing,
            'billed' => $t['billed'],
            'received' => $t['received'],
            'projects' => $client->projects()->orderBy('name')->get(),
            'payments' => $client->payments()->with(['project:id,name', 'documents'])->latest('paid_on')->latest('id')->get(),
            'invoices' => $invoices->where('status', '!=', 'draft'),
            'futureInvoices' => $invoices->where('status', 'draft'),
            'statement' => ($money = auth()->user()->can('manage-money')) ? $this->statement($client) : null,
            'subscriptions' => $money ? $this->subscriptions($client) : null,
        ]);
    }
}

<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Enums\PaymentType;
use App\Livewire\Concerns\DeletesClient;
use App\Livewire\Concerns\FiltersStatement;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Log;
use App\Services\ClientAccount;
use App\Models\BillableLine;
use App\Services\LocalCurrency;
use App\Support\MoneyStats;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use DeletesClient, FiltersStatement;

    #[Locked]
    public int $clientId;

    public array $login = ['name' => '', 'email' => '', 'password' => ''];

    /** Keyed by user id. */
    public array $newPassword = [];

    // Tab filters: one set per tab, since the tabs share this component. The statement uses from/to.
    public string $projectSearch = '';

    public string $projectStatus = '';

    public string $invoiceSearch = '';

    public string $invoiceStatus = '';

    public string $invoiceFrom = '';

    public string $invoiceTo = '';

    public string $paymentType = '';

    public string $paymentFrom = '';

    public string $paymentTo = '';

    public string $subscriptionSearch = '';

    public string $subscriptionStatus = '';

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

    public function setStatus(string $status): void
    {
        $client = Client::findOrFail($this->clientId);
        $this->authorize('update', $client);
        $client->update(['status' => ClientStatus::from($status)]);
        $this->dispatch('toast', type: 'success', message: 'Status updated.');
    }

    /** Block or restore portal sign-in for every login of this client; blocked users are signed out on their next request. */
    public function togglePortalBlock(): void
    {
        $client = Client::findOrFail($this->clientId);
        $this->authorize('update', $client);
        $client->update(['portal_blocked_at' => $client->portalBlocked() ? null : now()]);
        $this->dispatch('toast', type: 'success', message: $client->portalBlocked() ? 'Portal access blocked.' : 'Portal access restored.');
    }

    public function togglePortalFeature(string $feature): void
    {
        abort_unless(array_key_exists($feature, Client::PORTAL_FEATURES), 404);
        $client = Client::findOrFail($this->clientId);
        $this->authorize('update', $client);
        $on = $client->portal_features ?? [];
        $client->update(['portal_features' => array_values($client->portalShows($feature) ? array_diff($on, [$feature]) : [...$on, $feature])]);
        $this->dispatch('toast', type: 'success', message: Client::PORTAL_FEATURES[$feature][0].($client->portalShows($feature) ? ' shown in the portal.' : ' hidden from the portal.'));
    }

    /** A portal login for this client: a user with the client role, tied to this client only. */
    public function addLogin(): void
    {
        $client = Client::findOrFail($this->clientId);
        $this->authorize('update', $client);
        $data = $this->validate([
            'login.name' => 'required|string|max:255',
            'login.email' => 'required|email|max:255|unique:users,email',
            'login.password' => ['required', Password::defaults()],
        ])['login'];
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'client_id' => $client->id]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole('client');
        $this->reset('login');
        $this->dispatch('toast', type: 'success', message: 'Portal login added.');
    }

    public function setLoginPassword(int $userId): void
    {
        $user = $this->clientLogin($userId);
        $this->validate(['newPassword.'.$userId => ['required', Password::defaults()]], [], ['newPassword.'.$userId => 'password']);
        $user->update(['password' => Hash::make($this->newPassword[$userId])]);
        unset($this->newPassword[$userId]);
        $this->dispatch('toast', type: 'success', message: 'Password changed.');
    }

    public function removeLogin(int $userId): void
    {
        $this->clientLogin($userId)->delete();
        $this->dispatch('toast', type: 'success', message: 'Portal login removed.');
    }

    // Row deletes from the tabs: each record must belong to THIS client, else 404.

    public function deleteProject(int $id): void
    {
        $this->authorize('update', Client::findOrFail($this->clientId));
        $project = Project::where('client_id', $this->clientId)->find($id);
        abort_unless($project, 404);
        if ($project->payments()->exists() || BillableLine::where('project_id', $id)->whereNotNull('invoice_id')->exists()) {
            $this->dispatch('toast', type: 'error', message: 'This project has payments or invoiced work, so it cannot be deleted. Mark it completed instead.');

            return;
        }
        $project->delete();
        Log::info('project deleted', ['id' => $id, 'client_id' => $this->clientId, 'user_id' => auth()->id()]);
        $this->dispatch('toast', type: 'success', message: 'Project deleted.');
    }

    public function deleteInvoice(int $id, InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $invoice = Invoice::where('client_id', $this->clientId)->find($id);
        abort_unless($invoice, 404);
        $svc->delete($invoice);
        $this->dispatch('toast', type: 'success', message: 'Invoice deleted.');
    }

    /** Same rules as the invoices list: draft -> sent (emails once) or sent -> draft. */
    public function setInvoiceStatus(int $id, string $status, InvoiceService $svc): void
    {
        $this->authorize('manage-money');
        $invoice = Invoice::where('client_id', $this->clientId)->find($id);
        abort_unless($invoice, 404);
        $ok = $svc->setStatus($invoice, $status);
        $this->dispatch('toast', type: $ok ? 'success' : 'error', message: $ok ? 'Status updated.' : 'This invoice cannot change status.');
    }

    public function deletePayment(int $id): void
    {
        $this->authorize('manage-money');
        $p = Payment::where('client_id', $this->clientId)->find($id);
        abort_unless($p, 404);
        $p->delete();
        // audit trail only: ids and amounts, never the free-text notes
        Log::info('payment deleted', ['id' => $p->id, 'client_id' => $p->client_id, 'amount_cents' => $p->amount_cents, 'paid_on' => $p->paid_on->toDateString(), 'user_id' => auth()->id()]);
        $this->dispatch('toast', type: 'success', message: 'Payment deleted.');
    }

    public function deleteSubscription(int $id): void
    {
        $this->authorize('manage-money');
        $c = RecurringCharge::where('client_id', $this->clientId)->find($id);
        abort_unless($c, 404);
        $ok = $c->deleteIfNeverInvoiced();
        $this->dispatch('toast', type: $ok ? 'success' : 'error', message: $ok ? 'Subscription deleted.' : RecurringCharge::KEEP_HISTORY);
    }

    public function setInvoicePeriod(string $period): void
    {
        [$this->invoiceFrom, $this->invoiceTo] = $this->periods()[$period] ?? ['', ''];
    }

    public function setPaymentPeriod(string $period): void
    {
        [$this->paymentFrom, $this->paymentTo] = $this->periods()[$period] ?? ['', ''];
    }

    /** LIKE term for a tab search (use with ESCAPE '!'); null when empty. */
    private function like(string $s): ?string
    {
        $t = mb_substr(trim($s), 0, 100);

        return $t === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t).'%';
    }

    private function between($q, string $column, string $from, string $to)
    {
        return $q->when($this->validDate($from), fn ($q) => $q->whereDate($column, '>=', $from))
            ->when($this->validDate($to), fn ($q) => $q->whereDate($column, '<=', $to));
    }

    /** A client-role login of THIS client, or 404. */
    private function clientLogin(int $userId): User
    {
        $this->authorize('update', Client::findOrFail($this->clientId));

        $user = User::role('client')->where('client_id', $this->clientId)->find($userId);
        abort_unless($user, 404);

        return $user;
    }

    /** Recurring charges with how often and how much they have been put on sent invoices (matches the recurring page). */
    private function subscriptions(Client $client)
    {
        $like = $this->like($this->subscriptionSearch);
        $charges = $client->recurringCharges()->withUsage()->with('project')
            ->when($this->subscriptionStatus === 'active', fn ($q) => $q->where('active', true))
            ->when($this->subscriptionStatus === 'paused', fn ($q) => $q->where('active', false))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("description LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('project', fn ($p) => $p->whereRaw("name LIKE ? ESCAPE '!'", [$like]))))
            ->orderByDesc('active')->orderByRaw('CASE WHEN NOT active THEN COALESCE(ended_at, updated_at) END DESC')->orderBy('next_due_on')->get();
        return $charges->each(function ($c) use ($client) {
            $c->times_invoiced = (int) $c->invoiced_count;
            $c->total_invoiced = (int) $c->invoiced_cents;
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

        $invoiceQuery = $this->between(Invoice::where('client_id', $client->id), 'issued_on', $this->invoiceFrom, $this->invoiceTo)
            ->when(in_array($this->invoiceStatus, ['sent', 'draft', 'void'], true), fn ($q) => $q->where('invoices.status', $this->invoiceStatus))
            ->when($this->like($this->invoiceSearch), fn ($q, $like) => $q->whereRaw("number LIKE ? ESCAPE '!'", [$like]));
        $listed = (clone $invoiceQuery)->latest('issued_on')->latest('id')->get();
        $paymentQuery = $this->between(Payment::where('client_id', $client->id), 'paid_on', $this->paymentFrom, $this->paymentTo)
            ->when(PaymentType::tryFrom($this->paymentType), fn ($q, $t) => $q->where('type', $t->value));
        $money = auth()->user()->can('manage-money');

        return view('livewire.clients.show', [
            'client' => $client,
            'localCode' => $code,
            'billedLocal' => $billedLocal,
            'receivedLocal' => $receivedLocal,
            'billedMissing' => $billedMissing,
            'receivedMissing' => $receivedMissing,
            'billed' => $t['billed'],
            'received' => $t['received'],
            'projects' => $client->projects()
                ->when($this->projectStatus !== '', fn ($q) => $q->where('status', $this->projectStatus))
                ->when($this->like($this->projectSearch), fn ($q, $like) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$like]))
                ->orderBy('name')->get(),
            'payments' => (clone $paymentQuery)->with(['project:id,name', 'documents'])->latest('paid_on')->latest('id')->get(),
            'invoiceStats' => $money ? MoneyStats::invoices($invoiceQuery) : null,
            'paymentStats' => $money ? MoneyStats::payments($paymentQuery) : null,
            'paymentsByCurrency' => $money ? (clone $paymentQuery)->get(['currency_id', 'amount_cents'])->groupBy(fn ($p) => $p->currency ?? $code)
                ->map(fn ($rows) => ['total' => (int) $rows->sum('amount_cents'), 'average' => intdiv((int) $rows->sum('amount_cents'), $rows->count())])->all() : [],
            'invoices' => $listed->where('status', '!=', 'draft'),
            'futureInvoices' => $listed->where('status', 'draft'),
            'invoicePeriod' => array_search([$this->invoiceFrom, $this->invoiceTo], $this->periods(), true),
            'paymentPeriod' => array_search([$this->paymentFrom, $this->paymentTo], $this->periods(), true),
            'statement' => $money ?$this->statement($client) : null,
            'subscriptions' => $money ? $this->subscriptions($client) : null,
            'logins' => User::role('client')->where('client_id', $client->id)->orderBy('name')->get(['id', 'name', 'email', 'created_at']),
        ]);
    }
}

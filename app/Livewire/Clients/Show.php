<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
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
use Illuminate\Support\Facades\DB;
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
        $charges = $client->recurringCharges()->with('project')->orderByDesc('active')->orderByRaw('CASE WHEN NOT active THEN COALESCE(ended_at, updated_at) END DESC')->orderBy('next_due_on')->get();
        $billed = BillableLine::whereIn('recurring_charge_id', $charges->pluck('id'))
            ->whereHas('invoice', fn ($q) => $q->where('status', 'sent'))
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
            'logins' => User::role('client')->where('client_id', $client->id)->orderBy('name')->get(['id', 'name', 'email', 'created_at']),
        ]);
    }
}

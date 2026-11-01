<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;

class ClientAccount
{
    // payments received minus everything invoiced (non-void); positive = client has credit
    public function balanceCents(Client $client): int
    {
        return (int) $client->payments()->sum('amount_cents')
            - (int) Invoice::where('client_id', $client->id)->where('status', 'sent')->sum('total_cents');
    }

    /** [client_id => ['billed','received'] cents] via two grouped queries; same semantics as balanceCents. */
    public function totals(array $clientIds): array
    {
        $billed = Invoice::whereIn('client_id', $clientIds)->where('status', 'sent')->groupBy('client_id')->selectRaw('client_id, sum(total_cents) as s')->pluck('s', 'client_id');
        $received = Payment::whereIn('client_id', $clientIds)->groupBy('client_id')->selectRaw('client_id, sum(amount_cents) as s')->pluck('s', 'client_id');

        return collect($clientIds)->mapWithKeys(fn ($id) => [$id => ['billed' => (int) ($billed[$id] ?? 0), 'received' => (int) ($received[$id] ?? 0)]])->all();
    }

    public function softBalanceCents(Project $project): int
    {
        // float sum then clamp: absurd stored budgets must not overflow into a float/TypeError
        $budget = (int) min((float) PHP_INT_MAX / 2, $project->modules->sum(fn ($m) => (float) ($m->budgetCents() ?? 0)));
        $paid = (int) Payment::where('project_id', $project->id)->where('client_id', $project->client_id)->sum('amount_cents');

        return max(0, $budget - $paid);
    }
}

<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Support\Money;

class ClientAccount
{
    /** Payments received minus everything invoiced (sent invoices only), in the client's own currency; positive = credit. */
    public function balanceCents(Client $client): int
    {
        $t = $this->totals([$client->id])[$client->id];

        return $t['received'] - $t['billed'];
    }

    /** Same balance in the local currency from the stored local amounts; null when any row lacks one. */
    public function localBalanceCents(Client $client): ?int
    {
        $t = $this->localTotals([$client->id])[$client->id];

        return $t['missing'] > 0 ? null : $t['received'] - $t['billed'];
    }

    /**
     * [client_id => ['billed','received'] cents] in each client's own currency. Rows in another currency are
     * converted through the local currency at the client's active rate (skipped when it has none).
     */
    public function totals(array $clientIds): array
    {
        $clients = Client::whereIn('id', $clientIds)->get()->keyBy('id');
        $billed = Invoice::whereIn('client_id', $clientIds)->where('status', 'sent')->groupBy('client_id', 'currency_id')
            ->selectRaw('client_id, currency_id, sum(total_cents) as s, sum(local_total_cents) as l, sum(case when local_total_cents is null then 1 else 0 end) as n')->get();
        $received = Payment::whereIn('client_id', $clientIds)->groupBy('client_id', 'currency_id')
            ->selectRaw('client_id, currency_id, sum(amount_cents) as s, sum(local_amount_cents) as l, sum(case when local_amount_cents is null then 1 else 0 end) as n')->get();

        $fold = function ($groups) use ($clients) {
            $out = [];
            foreach ($groups as $g) {
                $client = $clients[$g->client_id];
                if ($g->currency_id === $client->currency_id) {
                    $v = (int) $g->s;
                } else {
                    $rate = LocalCurrency::rateFor($client->currency);
                    $v = $rate !== null && (int) $g->n === 0 ? Money::unconvert((int) $g->l, $rate) : 0;
                }
                $out[$g->client_id] = ($out[$g->client_id] ?? 0) + $v;
            }

            return $out;
        };
        $b = $fold($billed);
        $r = $fold($received);

        return collect($clientIds)->mapWithKeys(fn ($id) => [$id => ['billed' => (int) ($b[$id] ?? 0), 'received' => (int) ($r[$id] ?? 0)]])->all();
    }

    /** [client_id => ['billed','received','missing']] cents in the local currency; missing = rows without a local amount. */
    public function localTotals(array $clientIds): array
    {
        $billed = Invoice::whereIn('client_id', $clientIds)->where('status', 'sent')->groupBy('client_id')
            ->selectRaw('client_id, coalesce(sum(local_total_cents), 0) as s, sum(case when local_total_cents is null then 1 else 0 end) as n')->get()->keyBy('client_id');
        $received = Payment::whereIn('client_id', $clientIds)->groupBy('client_id')
            ->selectRaw('client_id, coalesce(sum(local_amount_cents), 0) as s, sum(case when local_amount_cents is null then 1 else 0 end) as n')->get()->keyBy('client_id');

        return collect($clientIds)->mapWithKeys(fn ($id) => [$id => [
            'billed' => (int) ($billed[$id]->s ?? 0),
            'received' => (int) ($received[$id]->s ?? 0),
            'missing' => (int) ($billed[$id]->n ?? 0) + (int) ($received[$id]->n ?? 0),
        ]])->all();
    }

    public function softBalanceCents(Project $project): int
    {
        // float sum then clamp: absurd stored budgets must not overflow into a float/TypeError
        $budget = (int) min((float) PHP_INT_MAX / 2, $project->modules->sum(fn ($m) => (float) Money::toCents($m->budgetAmount() ?? '0')));
        $paid = (int) Payment::where('project_id', $project->id)->where('client_id', $project->client_id)->sum('amount_cents');

        return max(0, $budget - $paid);
    }
}

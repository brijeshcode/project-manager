<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Payment;
use App\Services\ClientAccount;
use Carbon\CarbonInterface;

class DashboardMoney
{
    /** Per-client-currency totals in cents; currencies are never mixed. */
    public static function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $received = fn (CarbonInterface $a, CarbonInterface $b) => Payment::query()
            ->join('clients', 'clients.id', '=', 'payments.client_id')
            ->whereBetween('payments.paid_on', [$a->toDateString(), $b->toDateString()])
            ->groupBy('clients.currency')
            ->selectRaw('clients.currency as cur, SUM(payments.amount_cents) as total')
            ->pluck('total', 'cur')
            ->map(fn ($v) => (int) $v)
            ->all();

        $outstanding = [];
        $account = app(ClientAccount::class);
        foreach (Client::all() as $client) {
            $balance = $account->balanceCents($client);
            if ($balance < 0) {
                $outstanding[$client->currency] = ($outstanding[$client->currency] ?? 0) + abs($balance);
            }
        }

        return [
            'received' => $received($from, $to),
            'outstanding' => $outstanding,
            'this_month' => $received(now()->startOfMonth(), now()->endOfMonth()),
        ];
    }

    public static function owingClients(): int
    {
        $account = app(ClientAccount::class);

        return Client::all()->filter(fn ($c) => $account->balanceCents($c) < 0)->count();
    }
}

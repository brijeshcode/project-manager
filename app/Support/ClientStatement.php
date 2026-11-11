<?php

namespace App\Support;

use App\Models\Client;
use App\Services\LocalCurrency;
use Illuminate\Support\Collection;

class ClientStatement
{
    /**
     * Sent invoices (debit) and payments (credit), oldest first, each with 'amount' and running 'balance' in the client's
     * currency (balance = paid - invoiced, negative = owing). Converts exactly like ClientAccount::totals: rows in another
     * currency go through their frozen local amounts at the client's active rate, per currency group, so the final balance
     * equals ClientAccount::balanceCents. 'amount' is null for rows that totals() leaves out (no rate / missing local amount).
     */
    public static function rows(Client $client): Collection
    {
        $rows = $client->invoices()->where('status', 'sent')->get()
            ->map(fn ($i) => ['type' => 'invoice', 'model' => $i, 'date' => $i->issued_on, 'cents' => (int) $i->total_cents, 'local' => $i->local_total_cents, 'currency_id' => $i->currency_id, 'currency' => $i->currency])
            ->concat($client->payments()->with('project:id,name')->get()
                ->map(fn ($p) => ['type' => 'payment', 'model' => $p, 'date' => $p->paid_on, 'cents' => (int) $p->amount_cents, 'local' => $p->local_amount_cents, 'currency_id' => $p->currency_id, 'currency' => $p->currency]))
            ->sortBy([fn ($a, $b) => (string) $a['date']?->toDateString() <=> (string) $b['date']?->toDateString(), ['type', 'asc'], fn ($a, $b) => $a['model']->id <=> $b['model']->id])
            ->values();

        $foreign = fn ($r) => $r['currency_id'] != $client->currency_id;
        $rate = LocalCurrency::rateFor($client->currency);
        $broken = $rows->filter(fn ($r) => $foreign($r) && $r['local'] === null)->map(fn ($r) => $r['type'].$r['currency_id'])->flip();
        $cum = [];
        $balance = 0;

        return $rows->map(function ($r) use ($foreign, $rate, $broken, &$cum, &$balance) {
            $key = $r['type'].$r['currency_id'];
            if (! $foreign($r)) {
                $amount = $r['cents'];
            } elseif ($rate === null || isset($broken[$key])) {
                $amount = null;
            } else {
                // difference of cumulative conversions: per-row rounding adds up to the group total totals() computes
                $prev = $cum[$key] ?? 0;
                $cum[$key] = $prev + (int) $r['local'];
                $amount = Money::unconvert($cum[$key], $rate) - Money::unconvert($prev, $rate);
            }
            $balance += ($r['type'] === 'payment' ? 1 : -1) * ($amount ?? 0);

            return $r + ['amount' => $amount, 'balance' => $balance, 'foreign' => $foreign($r)];
        });
    }
}

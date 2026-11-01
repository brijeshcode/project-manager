<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Payment;
use App\Services\ClientAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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

    /**
     * Payments received per bucket, per client currency (never mixed), zero-filled.
     * $granularity: 'day' | 'week' (Monday start) | 'month'.
     * Returns [CUR => [['label' => 'Oct 5', 'cents' => 1500], ...]]; [] when nothing was received.
     */
    public static function revenueSeries(CarbonInterface $from, CarbonInterface $to, string $granularity = 'day'): array
    {
        $rows = Payment::query()
            ->join('clients', 'clients.id', '=', 'payments.client_id')
            ->whereDate('payments.paid_on', '>=', $from->toDateString())
            ->whereDate('payments.paid_on', '<=', $to->toDateString())
            ->groupBy('clients.currency', DB::raw('DATE(payments.paid_on)'))
            ->selectRaw('clients.currency as cur, DATE(payments.paid_on) as d, SUM(payments.amount_cents) as total')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $key = fn (CarbonInterface $d) => match ($granularity) {
            'week' => $d->copy()->startOfWeek()->toDateString(),
            'month' => $d->copy()->startOfMonth()->toDateString(),
            default => $d->toDateString(),
        };
        $label = fn (CarbonInterface $d) => match ($granularity) {
            'month' => $d->format('M Y'),
            default => $d->format('M j'),
        };

        $buckets = [];
        for ($d = Carbon::parse($key($from)); $d->lte($to); $d = match ($granularity) {
            'week' => $d->copy()->addWeek(),
            'month' => $d->copy()->addMonth(),
            default => $d->copy()->addDay(),
        }) {
            $buckets[$d->toDateString()] = $label($d);
        }

        $series = [];
        foreach ($rows->groupBy('cur') as $cur => $group) {
            $sums = [];
            foreach ($group as $row) {
                $k = $key(Carbon::parse($row->d));
                $sums[$k] = ($sums[$k] ?? 0) + (int) $row->total;
            }
            foreach ($buckets as $k => $text) {
                $series[$cur][] = ['label' => $text, 'cents' => $sums[$k] ?? 0];
            }
        }

        return $series;
    }

    public static function owingClients(): int
    {
        $account = app(ClientAccount::class);

        return Client::all()->filter(fn ($c) => $account->balanceCents($c) < 0)->count();
    }
}

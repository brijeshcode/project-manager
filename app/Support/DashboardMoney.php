<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Payment;
use App\Services\ClientAccount;
use App\Services\LocalCurrency;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class DashboardMoney
{
    /**
     * Local-currency totals in cents, integer math only. Received / this month sum the stored local amounts
     * of payments recorded in the current local currency; NULL-local payments are excluded and counted.
     * Outstanding converts each owing client's balance at the active rate; clients without one are skipped and counted.
     */
    public static function forPeriodLocal(CarbonInterface $from, CarbonInterface $to): array
    {
        $local = LocalCurrency::code();
        $received = fn (CarbonInterface $a, CarbonInterface $b) => (int) Payment::query()
            ->whereDate('paid_on', '>=', $a->toDateString())->whereDate('paid_on', '<=', $b->toDateString())
            ->where('local_currency', $local)
            ->sum('local_amount_cents');

        $outstanding = 0;
        $missing = 0;
        $clients = Client::query()->pluck('currency', 'id');
        $rates = [];
        foreach (app(ClientAccount::class)->totals($clients->keys()->all()) as $id => $t) {
            $balance = $t['received'] - $t['billed'];
            if ($balance >= 0) {
                continue;
            }
            $cur = strtoupper($clients[$id]);
            $rate = $rates[$cur] ??= LocalCurrency::rateFor($cur);
            if ($rate === null) {
                $missing++;
            } else {
                $outstanding += Money::convert(abs($balance), $rate);
            }
        }

        return [
            'currency' => $local,
            'received' => $received($from, $to),
            'this_month' => $received(now()->startOfMonth(), now()->endOfMonth()),
            'outstanding' => $outstanding,
            'outstanding_missing_rate' => $missing,
            'payments_missing_rate' => Payment::query()
                ->whereDate('paid_on', '>=', $from->toDateString())->whereDate('paid_on', '<=', $to->toDateString())
                ->whereNull('local_amount_cents')->count(),
        ];
    }

    /**
     * Local-currency payments per bucket, zero-filled. $granularity: 'day' | 'week' (Monday start) | 'month'.
     * Returns [['label' => 'Oct 5', 'cents' => 1500], ...]; [] when nothing was received.
     */
    public static function revenueSeriesLocal(CarbonInterface $from, CarbonInterface $to, string $granularity = 'day'): array
    {
        $rows = Payment::query()
            ->whereDate('paid_on', '>=', $from->toDateString())
            ->whereDate('paid_on', '<=', $to->toDateString())
            ->where('local_currency', LocalCurrency::code())
            ->groupBy(DB::raw('DATE(paid_on)'))
            ->selectRaw('DATE(paid_on) as d, SUM(local_amount_cents) as total')
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

        $sums = [];
        foreach ($rows as $row) {
            $k = $key(Carbon::parse($row->d));
            $sums[$k] = ($sums[$k] ?? 0) + (int) $row->total;
        }

        $series = [];
        foreach ($buckets as $k => $text) {
            $series[] = ['label' => $text, 'cents' => $sums[$k] ?? 0];
        }

        return $series;
    }

    public static function owingClients(): int
    {
        $account = app(ClientAccount::class);

        return Client::all()->filter(fn ($c) => $account->balanceCents($c) < 0)->count();
    }
}

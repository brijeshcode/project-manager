<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Invoice;
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
            if (! array_key_exists($cur, $rates)) {
                $rates[$cur] = LocalCurrency::rateFor($cur);
            }
            $rate = $rates[$cur];
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

    private const PAID_SQL = '(select coalesce(sum(amount_cents), 0) from payments where payments.invoice_id = invoices.id) >= invoices.total_cents and invoices.total_cents > 0';

    /** paid | pending | overdue | void for an invoice loaded withSum('payments', 'amount_cents'). */
    public static function invoiceState(Invoice $i): string
    {
        if ($i->status === 'void') {
            return 'void';
        }
        if ($i->total_cents > 0 && (int) $i->payments_sum_amount_cents >= $i->total_cents) {
            return 'paid';
        }

        return $i->due_on !== null && $i->due_on->toDateString() < now()->toDateString() ? 'overdue' : 'pending';
    }

    /** Invoice counts by state for invoices issued in [from, to] (inclusive). Counts only, no money. */
    public static function invoiceStatusCounts(CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = ['paid' => 0, 'pending' => 0, 'overdue' => 0, 'void' => 0];
        Invoice::withSum('payments', 'amount_cents')
            ->whereDate('issued_on', '>=', $from->toDateString())->whereDate('issued_on', '<=', $to->toDateString())
            ->get()->each(function (Invoice $i) use (&$counts) {
                $counts[self::invoiceState($i)]++;
            });

        return $counts;
    }

    private static function sentUnpaid()
    {
        return Invoice::where('status', 'sent')->whereRaw('not ('.self::PAID_SQL.')');
    }

    public static function overdueCount(): int
    {
        return self::sentUnpaid()->whereDate('due_on', '<', now()->toDateString())->count();
    }

    public static function sentThisMonthCount(): int
    {
        return Invoice::where('status', '!=', 'void')
            ->whereDate('issued_on', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('issued_on', '<=', now()->endOfMonth()->toDateString())->count();
    }

    public static function recentInvoices(int $limit = 5)
    {
        return Invoice::with('client')->withSum('payments', 'amount_cents')
            ->orderByDesc('issued_on')->orderByDesc('id')->limit($limit)->get();
    }

    public static function upcomingDues(int $limit = 5)
    {
        return self::sentUnpaid()->with('client')->whereDate('due_on', '>=', now()->toDateString())
            ->orderBy('due_on')->orderBy('id')->limit($limit)->get();
    }

    /**
     * Top clients by local revenue in [from, to] (zero-revenue clients included), name as tie-break.
     * Rows: ['client' => Client, 'revenue' => int, 'invoices' => int, 'outstanding' => ?int (null = no rate)].
     */
    public static function topClients(CarbonInterface $from, CarbonInterface $to, int $limit = 5): array
    {
        $revenue = Payment::query()
            ->whereDate('paid_on', '>=', $from->toDateString())->whereDate('paid_on', '<=', $to->toDateString())
            ->where('local_currency', LocalCurrency::code())
            ->groupBy('client_id')->selectRaw('client_id, SUM(local_amount_cents) as s')->pluck('s', 'client_id');
        $invoices = Invoice::where('status', '!=', 'void')
            ->whereDate('issued_on', '>=', $from->toDateString())->whereDate('issued_on', '<=', $to->toDateString())
            ->groupBy('client_id')->selectRaw('client_id, COUNT(*) as n')->pluck('n', 'client_id');

        $top = Client::query()->get()
            ->sort(fn ($a, $b) => [(int) ($revenue[$b->id] ?? 0), strtolower($a->name)] <=> [(int) ($revenue[$a->id] ?? 0), strtolower($b->name)])
            ->take($limit)->values();

        $totals = app(ClientAccount::class)->totals($top->pluck('id')->all());

        return $top->map(function (Client $c) use ($revenue, $invoices, $totals) {
            $balance = $totals[$c->id]['received'] - $totals[$c->id]['billed'];
            $rate = $balance < 0 ? LocalCurrency::rateFor($c->currency) : null;

            return [
                'client' => $c,
                'revenue' => (int) ($revenue[$c->id] ?? 0),
                'invoices' => (int) ($invoices[$c->id] ?? 0),
                'outstanding' => $balance >= 0 ? 0 : ($rate === null ? null : Money::convert(abs($balance), $rate)),
            ];
        })->all();
    }
}

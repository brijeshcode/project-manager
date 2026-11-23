<?php

namespace App\Support;

use App\Models\BillableLine;
use App\Models\Client;
use App\Models\ExpensePayment;
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
        // income: payments minus pass-through costs invoiced in the same window
        // cash: the TDS and deductions the clients kept are billed but never received
        $received = fn (CarbonInterface $a, CarbonInterface $b) => (int) Payment::query()
            ->whereDate('paid_on', '>=', $a->toDateString())->whereDate('paid_on', '<=', $b->toDateString())
            ->where('local_currency', $local)
            ->selectRaw('COALESCE(SUM(local_amount_cents), 0) - COALESCE(SUM(local_deductions_cents), 0) as net')
            ->value('net') - self::passThroughLocal($a, $b)->sum('cents');

        $outstanding = 0;
        $missing = 0;
        $clients = Client::query()->get()->pluck('currency', 'id');
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

        // money going out: expense payments in the window, stored local amounts only (NULL-local counted)
        $paidOut = fn () => ExpensePayment::query()
            ->whereDate('paid_on', '>=', $from->toDateString())->whereDate('paid_on', '<=', $to->toDateString());
        $expenses = (int) $paidOut()->where('local_currency', $local)->sum('local_amount_cents');
        $income = $received($from, $to);

        return [
            'currency' => $local,
            'received' => $income,
            'expenses' => $expenses,
            'net' => $income - $expenses,
            'expenses_missing_rate' => $paidOut()->whereNull('local_amount_cents')->count(),
            'pass_through' => self::passThroughLocal($from, $to)->sum('cents'),
            'this_month' => $received(now()->startOfMonth(), now()->endOfMonth()),
            'outstanding' => $outstanding,
            'outstanding_missing_rate' => $missing,
            'payments_missing_rate' => Payment::query()
                ->whereDate('paid_on', '>=', $from->toDateString())->whereDate('paid_on', '<=', $to->toDateString())
                ->whereNull('local_amount_cents')->count(),
        ];
    }

    /**
     * Pass-through costs (server, hosting passed on to the client) on SENT invoices issued in [from, to], in local
     * currency at each invoice's frozen rate, as rows of [issued_on, client_id, cents]. Payments can't be split into
     * income and pass-through, so income = payments − these amounts in the same window (timing may differ by a month).
     */
    public static function passThroughLocal(CarbonInterface $from, CarbonInterface $to): \Illuminate\Support\Collection
    {
        $local = LocalCurrency::code();

        return BillableLine::query()->join('invoices', 'invoices.id', '=', 'billable_lines.invoice_id')
            ->join('currencies', 'currencies.id', '=', 'invoices.currency_id')
            ->where('billable_lines.pass_through', true)->where('invoices.status', 'sent')
            ->whereDate('invoices.issued_on', '>=', $from->toDateString())->whereDate('invoices.issued_on', '<=', $to->toDateString())
            ->groupBy('invoices.id', 'invoices.issued_on', 'invoices.client_id', 'invoices.exchange_rate', 'currencies.code')
            ->selectRaw('invoices.issued_on as d, invoices.client_id as c, invoices.exchange_rate as rate, currencies.code as cur, SUM(billable_lines.amount_cents) as s')
            ->get()
            ->map(function ($r) use ($local) {
                $rate = strtoupper($r->cur) === $local ? '1' : ($r->rate !== null ? number_format((float) $r->rate, 6, '.', '') : null);

                return ['d' => Carbon::parse($r->d)->toDateString(), 'client_id' => (int) $r->c, 'cents' => $rate === null ? 0 : Money::convert((int) $r->s, $rate)];
            });
    }

    /**
     * Local-currency payments per bucket, zero-filled. $granularity: 'day' | 'week' (Monday start) | 'month' | 'quarter' | 'year'.
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

        $pass = self::passThroughLocal($from, $to);
        if ($rows->isEmpty() && $pass->isEmpty()) {
            return [];
        }

        $key = fn (CarbonInterface $d) => match ($granularity) {
            'week' => $d->copy()->startOfWeek()->toDateString(),
            'month' => $d->copy()->startOfMonth()->toDateString(),
            'quarter' => $d->copy()->startOfQuarter()->toDateString(),
            'year' => $d->copy()->startOfYear()->toDateString(),
            default => $d->toDateString(),
        };
        $label = fn (CarbonInterface $d) => match ($granularity) {
            'month' => $d->format('M Y'),
            'quarter' => 'Q'.$d->quarter.' '.$d->year,
            'year' => $d->format('Y'),
            default => $d->format('M j'),
        };

        $buckets = [];
        for ($d = Carbon::parse($key($from)); $d->lte($to); $d = match ($granularity) {
            'week' => $d->copy()->addWeek(),
            'month' => $d->copy()->addMonth(),
            'quarter' => $d->copy()->addQuarter(),
            'year' => $d->copy()->addYear(),
            default => $d->copy()->addDay(),
        }) {
            $buckets[$d->toDateString()] = $label($d);
        }

        $sums = [];
        foreach ($rows as $row) {
            $k = $key(Carbon::parse($row->d));
            $sums[$k] = ($sums[$k] ?? 0) + (int) $row->total;
        }
        // income, not cash: pass-through costs come off in the bucket their invoice was issued (a bucket can dip below zero)
        foreach ($pass as $p) {
            $k = $key(Carbon::parse($p['d']));
            $sums[$k] = ($sums[$k] ?? 0) - $p['cents'];
        }

        $series = [];
        foreach ($buckets as $k => $text) {
            $series[] = ['label' => $text, 'cents' => $sums[$k] ?? 0];
        }

        return $series;
    }

    /** Clients whose balance is negative (they owe money). Payments are not linked to invoices, so this decides "paid". */
    public static function owingClientIds(): array
    {
        $ids = Client::pluck('id')->all();

        return collect(app(ClientAccount::class)->totals($ids))->filter(fn ($t) => $t['received'] < $t['billed'])->keys()->all();
    }

    /** draft | void | paid | pending | overdue. Paid = the client owes nothing; overdue = sent, past due and the client still owes. */
    public static function invoiceState(Invoice $i, array $owing): string
    {
        if ($i->status === 'void' || $i->status === 'draft') {
            return $i->status;
        }
        if (! in_array($i->client_id, $owing, true)) {
            return 'paid';
        }

        return $i->due_on !== null && $i->due_on->toDateString() < now()->toDateString() ? 'overdue' : 'pending';
    }

    /** Invoice counts by state for invoices issued in [from, to] (inclusive); drafts are not issued, so not counted. */
    public static function invoiceStatusCounts(CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = ['paid' => 0, 'pending' => 0, 'overdue' => 0, 'void' => 0];
        $owing = self::owingClientIds();
        Invoice::where('status', '!=', 'draft')
            ->whereDate('issued_on', '>=', $from->toDateString())->whereDate('issued_on', '<=', $to->toDateString())
            ->get()->each(function (Invoice $i) use (&$counts, $owing) {
                $counts[self::invoiceState($i, $owing)]++;
            });

        return $counts;
    }

    private static function sentOwing()
    {
        return Invoice::where('status', 'sent')->whereIn('client_id', self::owingClientIds());
    }

    public static function overdueCount(): int
    {
        return self::sentOwing()->whereDate('due_on', '<', now()->toDateString())->count();
    }
    public static function sentThisMonthCount(): int
    {
        return Invoice::where('status', 'sent')
            ->whereDate('issued_on', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('issued_on', '<=', now()->endOfMonth()->toDateString())->count();
    }

    public static function recentInvoices(int $limit = 5)
    {
        return Invoice::with('client')->where('status', '!=', 'draft')
            ->orderByDesc('issued_on')->orderByDesc('id')->limit($limit)->get();
    }

    public static function upcomingDues(int $limit = 5)
    {
        return self::sentOwing()->with('client')->whereDate('due_on', '>=', now()->toDateString())
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
        foreach (self::passThroughLocal($from, $to)->groupBy('client_id') as $cid => $ps) {
            $revenue[$cid] = (int) ($revenue[$cid] ?? 0) - $ps->sum('cents'); // income, not cash
        }
        $invoices = Invoice::where('status', 'sent')
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

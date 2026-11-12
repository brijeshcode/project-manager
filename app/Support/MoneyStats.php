<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\LocalCurrency;
use Illuminate\Database\Eloquent\Builder;

/** Owner list-page stat cards. All cents in the local currency; cancelled (void) invoices never count. */
class MoneyStats
{
    /** Stats over the list's filtered query, sent invoices only. Amounts per own currency; local figures are approximate (invoices without a rate are skipped and counted in missing_rate). */
    public static function invoices(Builder $filtered): array
    {
        $sent = (clone $filtered)->reorder()->where('invoices.status', 'sent')
            ->get(['invoices.id', 'client_id', 'currency_id', 'issued_on', 'total_cents', 'local_total_cents']);
        $byCurrency = fn ($rows) => $rows->groupBy(fn ($i) => $i->currency ?? LocalCurrency::code())->map->sum('total_cents')->all();
        $month = $sent->filter(fn ($i) => $i->issued_on->isSameMonth(today()));
        $known = $sent->whereNotNull('local_total_cents');
        $knownLocal = (int) $known->sum('local_total_cents');

        return [
            'currency' => LocalCurrency::code(),
            'invoiced' => $byCurrency($sent),
            'invoiced_local' => $knownLocal,
            'count' => $sent->count(),
            'missing_rate' => $sent->count() - $known->count(),
            'month' => $byCurrency($month),
            'month_count' => $month->count(),
            'average' => $sent->groupBy(fn ($i) => $i->currency ?? LocalCurrency::code())->map(fn ($rows) => intdiv((int) $rows->sum('total_cents'), $rows->count()))->all(),
            'average_local' => $known->isEmpty() ? null : intdiv($knownLocal, $known->count()),
            'clients' => $sent->unique('client_id')->count(),
            'drafts' => (clone $filtered)->reorder()->where('invoices.status', 'draft')->count(),
        ];
    }

    /** Stats over the payments list's filtered query. Total and average are null when any counted payment has no local figure (all-or-nothing). */
    public static function payments(Builder $filtered): array
    {
        $local = LocalCurrency::code();
        $q = fn () => (clone $filtered)->reorder();
        $count = $q()->count();
        $missing = $q()->whereNull('local_amount_cents')->count();
        $total = $missing ? null : (int) $q()->where('local_currency', $local)->sum('local_amount_cents');
        $byType = $q()->where('local_currency', $local)->groupBy('type')->selectRaw('type, SUM(local_amount_cents) as cents')->pluck('cents', 'type');

        return [
            'currency' => $local,
            'total' => $total,
            'count' => $count,
            'clients' => $q()->distinct()->count('client_id'),
            'average' => $total !== null && $count > 0 ? intdiv($total + intdiv($count, 2), $count) : null,
            'missing_rate' => $missing,
            // ponytail: a type's sum skips rows without a rate; the missing_rate hint flags that
            'by_type' => $byType->map(fn ($c) => (int) $c)->sortDesc()->all(),
        ];
    }
}

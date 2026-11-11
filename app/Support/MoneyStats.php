<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\LocalCurrency;
use Illuminate\Database\Eloquent\Builder;

/** Owner list-page stat cards. All cents in the local currency; cancelled (void) invoices never count. */
class MoneyStats
{
    /** Stats over the list's filtered query. Sums are null when any counted invoice has no local figure (all-or-nothing). */
    public static function invoices(Builder $filtered): array
    {
        $rows = (clone $filtered)->get(['invoices.id', 'status', 'local_total_cents']);
        $sum = function ($rows) {
            $vals = $rows->map->localDisplayCents();

            return $vals->contains(null) ? null : (int) $vals->sum();
        };
        $sent = $rows->where('status', 'sent');
        $draft = $rows->where('status', 'draft');

        return [
            'currency' => LocalCurrency::code(),
            'total' => $sum($sent),
            'count' => $sent->count(),
            'missing_rate' => $sent->filter(fn ($i) => $i->localDisplayCents() === null)->count(),
            'draft' => $draft->count(),
            'draft_total' => $sum($draft),
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

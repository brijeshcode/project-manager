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

    public static function payments(): array
    {
        $local = LocalCurrency::code();
        $q = fn () => Payment::where('local_currency', $local);
        $count = $q()->count();
        $total = (int) $q()->sum('local_amount_cents');

        return [
            'currency' => $local,
            'total' => $total,
            'this_month' => (int) $q()->whereDate('paid_on', '>=', now()->startOfMonth()->toDateString())->sum('local_amount_cents'),
            'count' => Payment::count(),
            'average' => $count > 0 ? intdiv($total + intdiv($count, 2), $count) : 0,
            'missing_rate' => Payment::whereNull('local_amount_cents')->count(),
        ];
    }
}

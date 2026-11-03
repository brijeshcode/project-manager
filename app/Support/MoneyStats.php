<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\LocalCurrency;

/** Owner list-page stat cards. All cents in the local currency; cancelled (void) invoices never count. */
class MoneyStats
{
    /** total/this_month are null when any counted invoice has no local figure (all-or-nothing, like the dashboard). */
    public static function invoices(): array
    {
        $live = Invoice::where('status', 'sent')->get(['id', 'currency_id', 'total_cents', 'exchange_rate', 'issued_on']);
        $sum = function ($rows) {
            $vals = $rows->map->localDisplayCents();

            return $vals->contains(null) ? null : (int) $vals->sum();
        };
        $month = $live->filter(fn ($i) => $i->issued_on->between(now()->startOfMonth(), now()->endOfMonth()));
        $owed = DashboardMoney::forPeriodLocal(now()->startOfMonth(), now());

        return [
            'currency' => LocalCurrency::code(),
            'total' => $sum($live),
            'this_month' => $sum($month),
            'missing_rate' => $live->filter(fn ($i) => $i->localDisplayCents() === null)->count(),
            'count' => $live->count(),
            'outstanding' => $owed['outstanding'],
            'outstanding_missing_rate' => $owed['outstanding_missing_rate'],
            'cancelled' => Invoice::where('status', 'void')->count(),
            'draft' => Invoice::where('status', 'draft')->count(),
            'draft_total' => (function () use ($sum) {
                $d = Invoice::where('status', 'draft')->get(['id', 'currency_id', 'total_cents', 'exchange_rate', 'local_total_cents']);

                return $sum($d);
            })(),
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

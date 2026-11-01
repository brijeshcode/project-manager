<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocalCurrency
{
    public const DEFAULT = 'INR';

    public static function code(): string
    {
        return Settings::get('local_currency', self::DEFAULT);
    }

    public static function set(string $code, User $by): void
    {
        $code = ExchangeRates::normalize($code);
        if (self::isLocked()) {
            throw new \DomainException('Local currency cannot change once payments or invoices have been converted.');
        }
        DB::transaction(function () use ($code) {
            Settings::set('local_currency', $code);
            self::backfill();
        });
    }

    /** Legacy payments of clients already in the local currency convert at rate 1. */
    public static function backfill(): int
    {
        $code = self::code();

        return Payment::whereNull('local_amount_cents')
            ->whereIn('client_id', DB::table('clients')->where('currency', $code)->select('id'))
            ->update(['exchange_rate' => 1, 'local_amount_cents' => DB::raw('amount_cents'), 'local_currency' => $code]);
    }

    public static function isLocked(): bool
    {
        return DB::table('payments')->whereNotNull('local_amount_cents')->exists()
            || (Schema::hasColumn('invoices', 'exchange_rate') && DB::table('invoices')->whereNotNull('exchange_rate')->exists());
    }

    public static function rateFor(string $clientCurrency): ?string
    {
        return strtoupper($clientCurrency) === self::code() ? '1' : app(ExchangeRates::class)->active($clientCurrency);
    }
}

<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExchangeRates
{
    public static function normalize(string $currency): string
    {
        $c = strtoupper(trim($currency));
        if (! preg_match('/\A[A-Z]{3}\z/', $c)) {
            throw ValidationException::withMessages(['currency' => 'Currency must be a 3-letter code.']);
        }

        return $c;
    }

    public function active(string $currency): ?string
    {
        return ExchangeRate::where('currency', self::normalize($currency))->where('is_active', true)->latest('id')->first()?->rate;
    }

    /** Insert-only history: the previous active row is only deactivated, never edited otherwise. */
    public function setRate(string $currency, string $rate, User $by): ExchangeRate
    {
        $currency = self::normalize($currency);
        if (! Money::isValidRate($rate)) {
            throw ValidationException::withMessages(['rate' => 'Enter a positive rate with up to 6 decimals.']);
        }

        return DB::transaction(function () use ($currency, $rate, $by) {
            $prev = ExchangeRate::where('currency', $currency)->where('is_active', true)->lockForUpdate()->pluck('id');
            if ($prev->isNotEmpty()) {
                DB::table('exchange_rates')->whereIn('id', $prev)->update(['is_active' => false]);
            }

            return ExchangeRate::create(['currency' => $currency, 'rate' => $rate, 'is_active' => true, 'created_by' => $by->id]);
        });
    }

    public function activeAll(): Collection
    {
        return ExchangeRate::where('is_active', true)->orderBy('currency')->get();
    }

    public function history(string $currency): Collection
    {
        return ExchangeRate::with('creator')->where('currency', self::normalize($currency))->orderByDesc('id')->get();
    }
}

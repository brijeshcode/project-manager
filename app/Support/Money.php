<?php

namespace App\Support;

class Money
{
    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹'];

    /** Major-unit amount to integer cents, rounded half-up (away from zero) via string math, never float. */
    public static function toCents(string|int|float $amount): int
    {
        $s = str_replace(',', '', trim((string) $amount));
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException("Invalid amount: {$amount}");
        }
        $frac = str_pad($m[3] ?? '', 3, '0');
        $cents = (int) $m[2] * 100 + (int) substr($frac, 0, 2) + ((int) $frac[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function isValidRate(string $rate): bool
    {
        return preg_match('/\A\d{1,6}(\.\d{1,6})?\z/', $rate) === 1 && (float) $rate > 0;
    }

    /**
     * cents * rate, rounded half-up, in pure integer math (no float, no bcmath).
     * Rate becomes micro-units R (< 1e12); cents = q*1e6 + r so r*R < 1e18 never overflows.
     */
    public static function convert(int $cents, string $rate): int
    {
        if (! self::isValidRate($rate)) {
            throw new \InvalidArgumentException("Invalid exchange rate: {$rate}");
        }
        [$i, $f] = array_pad(explode('.', $rate), 2, '');
        $R = (int) ($i.str_pad($f, 6, '0'));
        $neg = $cents < 0;
        if ($cents === PHP_INT_MIN) {
            throw new \InvalidArgumentException('Amount too large to convert.');
        }
        $abs = abs($cents);
        $q = intdiv($abs, 1_000_000);
        $r = $abs % 1_000_000;
        $high = $q === 0 ? 0 : ($R > intdiv(PHP_INT_MAX, $q) ? null : $q * $R);
        $low = intdiv($r * $R + 500_000, 1_000_000);
        if ($high === null || $high > PHP_INT_MAX - $low) {
            throw new \InvalidArgumentException('Converted amount too large.');
        }

        return $neg ? -($high + $low) : $high + $low;
    }

    /** Inverse of convert: cents / rate, rounded half-up. Used to turn a local amount back into a client's currency. */
    public static function unconvert(int $cents, string $rate): int
    {
        if (! self::isValidRate($rate)) {
            throw new \InvalidArgumentException("Invalid exchange rate: {$rate}");
        }
        [$i, $f] = array_pad(explode('.', $rate), 2, '');
        $R = (int) ($i.str_pad($f, 6, '0'));
        if (abs($cents) > intdiv(PHP_INT_MAX, 1_000_000) - $R) {
            throw new \InvalidArgumentException('Amount too large to convert.');
        }
        $n = abs($cents) * 1_000_000;
        $q = intdiv($n + intdiv($R, 2), $R);

        return $cents < 0 ? -$q : $q;
    }

    public static function format(int $cents, string $currency): string
    {
        $currency = strtoupper($currency);
        $sym = self::SYMBOLS[$currency] ?? $currency.' ';

        return ($cents < 0 ? '-' : '').$sym.number_format(abs($cents) / 100, 2);
    }

    /** Cents to a plain decimal string for form inputs, e.g. 100050 -> "1000.50". */
    public static function toInput(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}

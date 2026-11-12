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

        return ($cents < 0 ? '-' : '').$sym.self::number(abs($cents));
    }

    /** True when the system number format is Indian (unit tests run without the app, so they get international). */
    public static function indian(): bool
    {
        return app()->bound('settings.cache') && Settings::numberFormat() === 'indian';
    }

    /** Cents as a grouped major-unit number without symbol, e.g. 123456789 -> "1,234,567.89" or "12,34,567.89". */
    public static function number(int $cents, int $decimals = 2): string
    {
        $s = number_format(abs($cents) / 100, $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $s), 2, null);
        $int = self::indian() && strlen($int) > 3
            ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)).','.substr($int, -3)
            : preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);

        return ($cents < 0 ? '-' : '').$int.($frac === null ? '' : '.'.$frac);
    }

    /** Short label from cents, rounded for display: 1,234 · 12.3K · 1.2M, or in Indian format 1,234 · 45.5K · 18L · 1.2Cr. */
    public static function compact(int $cents): string
    {
        $u = intdiv(abs($cents) + 50, 100);
        $sign = $cents < 0 ? '-' : '';
        $tenths = fn (int $div) => intdiv($u * 10 + intdiv($div, 2), $div);
        $fmt = fn (int $t, string $suffix) => self::number(intdiv($t, 10) * 100, 0).($t % 10 ? '.'.($t % 10) : '').$suffix;

        if ($u < 10000) {
            return $sign.self::number($u * 100, 0);
        }
        if (self::indian()) {
            return $sign.match (true) {
                $tenths(1000) < 1000 => $fmt($tenths(1000), 'K'),
                $tenths(100000) < 1000 => $fmt($tenths(100000), 'L'),
                default => $fmt($tenths(10000000), 'Cr'),
            };
        }

        return $sign.($tenths(1000) < 10000 ? $fmt($tenths(1000), 'K') : $fmt($tenths(1000000), 'M'));
    }

    /** Cents to a plain decimal string for form inputs, e.g. 100050 -> "1000.50". */
    public static function toInput(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}

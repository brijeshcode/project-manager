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

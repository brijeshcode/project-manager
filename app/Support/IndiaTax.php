<?php

namespace App\Support;

/** Rough Indian income-tax estimate in whole rupees, below-60 individual. Not filing-grade. */
class IndiaTax
{
    /** Per regime, newest first: [first FY start year, slabs as [upper limit, rate], 87A rebate limit]. ponytail: FY 2026-27 assumed same as 2025-26; add a row when slabs change. */
    private const REGIMES = [
        'new' => [
            [2025, [[400000, 0], [800000, .05], [1200000, .10], [1600000, .15], [2000000, .20], [2400000, .25], [PHP_INT_MAX, .30]], 1200000],
            [2023, [[300000, 0], [700000, .05], [1000000, .10], [1200000, .15], [1500000, .20], [PHP_INT_MAX, .30]], 700000],
        ],
        'old' => [
            [2023, [[250000, 0], [500000, .05], [1000000, .20], [PHP_INT_MAX, .30]], 500000],
        ],
    ];

    /** Tax on a taxable income (rupees) for the FY starting in $fy, with surcharge and 4% cess. */
    public static function tax(int $taxable, string $regime, int $fy): array
    {
        [, $slabs, $rebate] = collect(self::REGIMES[$regime])->first(fn ($r) => $fy >= $r[0]);
        $tax = 0;
        $prev = 0;
        foreach ($slabs as [$limit, $rate]) {
            $tax += max(0, min($taxable, $limit) - $prev) * $rate;
            $prev = $limit;
        }
        $tax = (int) round($tax);
        if ($taxable <= $rebate) {
            $tax = 0;
        } elseif ($regime === 'new') {
            $tax = min($tax, $taxable - $rebate);
        }
        // ponytail: no surcharge marginal relief; matters only just above 50L/1Cr/2Cr
        $surchargeRate = match (true) {
            $taxable > 50000000 && $regime === 'old' => .37,
            $taxable > 20000000 => .25,
            $taxable > 10000000 => .15,
            $taxable > 5000000 => .10,
            default => 0,
        };
        $surcharge = (int) round($tax * $surchargeRate);
        $cess = (int) round(($tax + $surcharge) * .04);

        return ['tax' => $tax, 'surcharge' => $surcharge, 'cess' => $cess, 'total' => $tax + $surcharge + $cess];
    }
}

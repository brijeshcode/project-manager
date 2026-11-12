<?php

namespace App\Models\Concerns;

use App\Services\LocalCurrency;
use App\Support\Money;

/**
 * Rows priced in a project's (or client's) currency keep amount + rate + local amount together.
 * The rate comes from the rate table; with none known, rate and local amounts stay null (never a fake 1:1) and are retried on the next save.
 * Models declare `localAmounts()` as [amount column => [local column, 'cents'|'major']] and `moneyCurrency()`.
 */
trait FreezesLocalAmounts
{
    abstract protected function localAmounts(): array;

    abstract protected function moneyCurrency(): ?string;

    public static function bootFreezesLocalAmounts(): void
    {
        static::saving(function ($m) {
            $cols = $m->localAmounts();
            $amountChanged = collect(array_keys($cols))->contains(fn ($c) => $m->isDirty($c));
            $explicit = $m->isDirty('exchange_rate') && $m->getAttribute('exchange_rate') !== null;
            if (! $amountChanged && ! $explicit && $m->getAttribute('exchange_rate') !== null) {
                return;
            }
            $rate = $explicit ? (string) $m->getAttribute('exchange_rate') : LocalCurrency::rateFor($m->moneyCurrency() ?? LocalCurrency::code());
            $rate = $rate === null ? null : number_format((float) $rate, 6, '.', ''); // same 6-decimal form the rate table uses
            $m->setAttribute('exchange_rate', $rate);
            foreach ($cols as $amount => [$local, $kind]) {
                $v = $m->getAttribute($amount);
                try {
                    $cents = $v === null ? null : ($kind === 'major' ? Money::toCents($v) : (int) $v);
                    $out = $cents === null || $rate === null ? null : Money::convert($cents, $rate);
                    $m->setAttribute($local, $out === null ? null : ($kind === 'major' ? Money::toInput($out) : $out));
                } catch (\Throwable) {
                    $m->setAttribute($local, null); // absurd stored values never block a save
                }
            }
        });
    }
}

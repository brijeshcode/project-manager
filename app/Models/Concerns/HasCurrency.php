<?php

namespace App\Models\Concerns;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stores currency_id; `$model->currency` reads and writes the 3-letter code so callers keep working. */
trait HasCurrency
{
    public function initializeHasCurrency(): void
    {
        $this->with[] = 'currencyRow';
    }

    public function currencyRow(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    protected function currency(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->currencyRow?->code,
            set: function ($code) {
                $row = $code === null || $code === '' ? null : Currency::forCode((string) $code);
                $this->setRelation('currencyRow', $row);

                return ['currency_id' => $row?->id];
            },
        );
    }
}

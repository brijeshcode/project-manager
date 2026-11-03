<?php

namespace App\Models;

use App\Support\Money;
use App\Models\Concerns\HasCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasCurrency;

    protected $fillable = [
        'number', 'client_id', 'status', 'issued_on', 'due_on', 'currency', 'subtotal_cents', 'tax_percent',
        'tax_cents', 'total_cents', 'bank_account_id', 'bank_snapshot', 'exchange_rate', 'local_total_cents', 'terms', 'emailed_at', 'lines_snapshot',
    ];

    protected $hidden = ['bank_snapshot'];

    protected static function booted(): void
    {
        // amount x rate = local amount, always: recomputed whenever the total or the rate changes
        static::saving(function (self $i) {
            if ($i->isDirty('exchange_rate') || $i->isDirty('total_cents')) {
                $i->local_total_cents = $i->exchange_rate === null ? null : Money::convert((int) $i->total_cents, (string) $i->exchange_rate);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'emailed_at' => 'datetime',
            'bank_snapshot' => 'encrypted:array',
            'tax_percent' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'lines_snapshot' => 'array',
        ];
    }

    /** Stored total in the local currency (total x rate); null when no rate was available. */
    public function localTotalCents(): ?int
    {
        return $this->local_total_cents;
    }

    public function localDisplayCents(): ?int
    {
        return $this->local_total_cents;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillableLine::class);
    }

    /** Not sent yet: no effect on the client balance, shown to the owner as a future invoice (estimate). */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}

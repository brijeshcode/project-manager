<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'number', 'client_id', 'status', 'issued_on', 'due_on', 'currency', 'subtotal_cents', 'tax_percent',
        'tax_cents', 'total_cents', 'bank_account_id', 'bank_snapshot', 'exchange_rate', 'terms', 'emailed_at', 'lines_snapshot',
    ];

    protected $hidden = ['bank_snapshot'];

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

    /** Total in the local currency at the rate frozen on this invoice; null when none was frozen. */
    public function localTotalCents(): ?int
    {
        return $this->exchange_rate === null ? null : Money::convert($this->total_cents, $this->exchange_rate);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillableLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paidCents(): int
    {
        return (int) $this->payments()->sum('amount_cents');
    }

    public function isPaid(): bool
    {
        return $this->total_cents > 0 && $this->paidCents() >= $this->total_cents;
    }
}

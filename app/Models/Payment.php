<?php

namespace App\Models;

use App\Enums\PaymentType;
use App\Models\Concerns\HasCurrency;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasCurrency;

    protected $fillable = ['client_id', 'project_id', 'amount_cents', 'paid_on', 'type', 'method', 'notes', 'exchange_rate', 'local_amount_cents', 'local_currency', 'currency', 'documents_visible_to_client', 'for_month', 'deductions_cents'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'type' => PaymentType::class, 'exchange_rate' => 'decimal:6', 'documents_visible_to_client' => 'boolean'];
    }

    /** The month it pays for, as "Jan 2024"; null when not set. */
    public function forMonthLabel(): ?string
    {
        return $this->for_month ? \Carbon\Carbon::createFromFormat('!Y-m', $this->for_month)->format('M Y') : null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            $p->currency_id ??= Client::whereKey($p->client_id)->value('currency_id');
        });
        // a payment in the local currency is its own local amount: rate 1, no rate needed
        static::saving(function (self $p) {
            if ($p->currency === LocalCurrency::code()) {
                $p->exchange_rate = 1;
                $p->local_amount_cents = $p->amount_cents;
                $p->local_currency = $p->currency;
            }
            $p->local_deductions_cents = $p->exchange_rate === null ? null : Money::convert((int) $p->deductions_cents, (string) $p->exchange_rate);
        });
        // the DB cascade removes the rows but not the files; delete per model so files go too
        static::deleting(fn (self $p) => $p->documents->each->delete());
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PaymentDocument::class);
    }

    public function hasLocal(): bool
    {
        return $this->local_amount_cents !== null;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

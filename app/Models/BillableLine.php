<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\FreezesLocalAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillableLine extends Model
{
    use FreezesLocalAmounts;

    protected $fillable = ['client_id', 'project_id', 'source_type', 'source_id', 'recurring_charge_id', 'period', 'description', 'quantity', 'unit_price_cents', 'amount_cents', 'exchange_rate', 'local_amount_cents', 'invoice_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    protected function localAmounts(): array
    {
        return ['amount_cents' => ['local_amount_cents', 'cents']];
    }

    protected function moneyCurrency(): ?string
    {
        return $this->project?->currency ?? $this->client?->currency;
    }

    /** Currency this line is priced in: its project's, else the client's. */
    public function lineCurrency(): ?string
    {
        return $this->moneyCurrency();
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereNull('invoice_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}

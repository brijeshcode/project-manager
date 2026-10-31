<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillableLine extends Model
{
    protected $fillable = ['client_id', 'project_id', 'source_type', 'source_id', 'recurring_charge_id', 'period', 'description', 'quantity', 'unit_price_cents', 'amount_cents', 'invoice_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
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

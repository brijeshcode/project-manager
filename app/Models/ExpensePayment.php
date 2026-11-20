<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ExpensePayment extends Model
{
    protected $fillable = ['expense_id', 'paid_on', 'amount_cents', 'exchange_rate', 'local_amount_cents', 'local_currency', 'method', 'notes'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'exchange_rate' => 'decimal:6'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** Receipts. Admin only: the download route checks manage-money. */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}

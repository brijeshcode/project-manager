<?php

namespace App\Models;

use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['client_id', 'project_id', 'invoice_id', 'amount_cents', 'paid_on', 'type', 'method', 'notes'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'type' => PaymentType::class];
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
}

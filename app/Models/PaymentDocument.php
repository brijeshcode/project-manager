<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PaymentDocument extends Model
{
    protected $fillable = ['payment_id', 'path', 'original_name', 'uploaded_by'];

    protected $hidden = ['path'];

    protected static function booted(): void
    {
        static::deleting(fn (self $d) => Storage::disk('local')->delete($d->path));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

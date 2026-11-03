<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleBudgetChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['module_id', 'user_id', 'field', 'old', 'new', 'reason'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

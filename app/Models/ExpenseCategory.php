<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'position', 'color', 'icon', 'icon_path'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}

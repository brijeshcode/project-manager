<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** The row for a 3-letter code, created on first use (name = code until the owner renames it). */
    public static function forCode(string $code): self
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/\A[A-Z]{3}\z/', $code)) {
            throw new \InvalidArgumentException("Invalid currency code: {$code}");
        }

        return self::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true]);
    }
}

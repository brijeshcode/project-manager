<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'name',
        'currency',
        // Contact
        'contact_name',
        'contact_email',
        'contact_phone',
        'address',
        // Billing
        'tax_number',
        'billing_address',
        'payment_terms',
        // Bank (encrypted)
        'bank_name',
        'bank_account_number',
        'bank_swift',
    ];

    protected function casts(): array
    {
        return [
            'bank_name'           => 'encrypted',
            'bank_account_number' => 'encrypted',
            'bank_swift'          => 'encrypted',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}

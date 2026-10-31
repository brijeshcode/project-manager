<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = ['label', 'account_name', 'bank_name', 'account_number', 'ifsc_swift', 'email'];

    protected $hidden = ['account_number', 'ifsc_swift'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'ifsc_swift' => 'encrypted'];
    }

    public function masked(): string
    {
        return '••••'.substr($this->account_number, -4);
    }
}

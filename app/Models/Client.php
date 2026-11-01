<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

class Client extends Model
{
    protected $fillable = [
        'name',
        'currency',
        'billing_mode',
        'type',
        'client_code',
        'status',
        'notes', // internal only: never shown in the portal
        // Contact
        'contact_name',
        'contact_email',
        'contact_phone',
        'website',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
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
            'billing_mode'        => \App\Enums\BillingMode::class,
            'type'                => ClientType::class,
            'status'              => ClientStatus::class,
            'bank_name'           => 'encrypted',
            'bank_account_number' => 'encrypted',
            'bank_swift'          => 'encrypted',
        ];
    }

    /** Next CLI-0001 style code; save() retries if a concurrent create takes it. */
    public static function nextCode(): string
    {
        $last = static::query()->whereNotNull('client_code')->orderByRaw('LENGTH(client_code) desc, client_code desc')->value('client_code');

        return sprintf('CLI-%04d', ((int) substr((string) $last, 4)) + 1);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }
        $this->type ??= ClientType::Company;
        $this->status ??= ClientStatus::Active;
        if ($this->client_code) {
            return parent::save($options);
        }
        for ($try = 1; ; $try++) {
            $this->client_code = static::nextCode();
            try {
                return parent::save($options);
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** Names of related data that forbid a hard delete. */
    public function deleteBlockers(): array
    {
        return collect([
            'projects' => $this->projects(), 'invoices' => $this->invoices(), 'payments' => $this->payments(),
            'billable lines' => $this->billableLines(), 'recurring charges' => $this->recurringCharges(), 'users' => $this->users(),
        ])->filter(fn ($rel) => $rel->exists())->keys()->all();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function billableLines(): HasMany
    {
        return $this->hasMany(BillableLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function recurringCharges(): HasMany
    {
        return $this->hasMany(RecurringCharge::class);
    }
}

<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Project;
use App\Models\User;
use App\Models\Concerns\HasCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

class Client extends Model
{
    use HasCurrency;

    /** Opt-in portal pages and figures; every one is hidden until ticked on the client's Access tab. */
    public const PORTAL_FEATURES = [
        'payments'       => ['Payments', 'Payments pages and recent payments on the dashboard.'],
        'statement'      => ['Statement', 'Statement page and account balance on the dashboard.'],
        'billing_totals' => ['Billing totals', 'Invoice count and total invoiced on Invoices; invoiced amounts and the view icon on Subscriptions.'],
    ];

    protected $fillable = [
        'name',
        'company_name', // billed party on invoices
        'currency',
        'billing_mode',
        'type',
        'client_code',
        'status',
        'is_internal',
        'inactive_since',
        'portal_blocked_at',
        'portal_features',
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
            'is_internal'         => 'boolean',
            'inactive_since'      => 'date',
            'portal_blocked_at'   => 'datetime',
            'portal_features'     => 'array',
            'bank_name'           => 'encrypted',
            'bank_account_number' => 'encrypted',
            'bank_swift'          => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // the date we stopped working with them: set on leaving Active, kept between Inactive and Archived, cleared on return
        static::saving(function (self $c) {
            if ($c->isDirty('status')) {
                $c->inactive_since = $c->status === ClientStatus::Active ? null : ($c->inactive_since ?? today());
            }
        });
    }

    public function scopeBillable($q)
    {
        return $q->where('is_internal', false);
    }

    public function portalBlocked(): bool
    {
        return $this->portal_blocked_at !== null;
    }

    public function portalShows(string $feature): bool
    {
        return in_array($feature, $this->portal_features ?? [], true);
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

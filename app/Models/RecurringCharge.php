<?php

namespace App\Models;

use App\Enums\RecurringFrequency;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringCharge extends Model
{
    protected $fillable = ['client_id', 'project_id', 'description', 'amount_cents', 'frequency', 'next_due_on', 'due_day', 'active'];

    protected function casts(): array
    {
        return ['frequency' => RecurringFrequency::class, 'next_due_on' => 'date', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->due_day ??= Carbon::parse($c->next_due_on)->day;
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Creates a pending line for every due period up to $today (catch-up), advancing one period each time. Returns lines created. */
    public function generateDue(CarbonInterface $today): int
    {
        $created = 0;
        while ($this->active && $this->next_due_on->lte($today)) {
            $due = $this->next_due_on;
            $monthly = $this->frequency === RecurringFrequency::Monthly;
            $line = BillableLine::createOrFirst(
                ['recurring_charge_id' => $this->id, 'period' => $due->format($monthly ? 'Y-m' : 'Y')],
                ['client_id' => $this->client_id, 'project_id' => $this->project_id, 'description' => $this->description,
                    'quantity' => 1, 'unit_price_cents' => $this->amount_cents, 'amount_cents' => $this->amount_cents],
            );
            $created += (int) $line->wasRecentlyCreated;

            $first = $due->copy()->startOfMonth()->{$monthly ? 'addMonth' : 'addYear'}();
            $this->next_due_on = $first->day(min($this->due_day, $first->daysInMonth));
            $this->save();
        }

        return $created;
    }
}

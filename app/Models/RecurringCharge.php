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

    private const MAX_PERIODS_PER_RUN = 36;

    /** Date one period after $due, keeping the due_day anchor. */
    private function advance(CarbonInterface $due): Carbon
    {
        $first = Carbon::parse($due)->startOfMonth()->{$this->frequency === RecurringFrequency::Monthly ? 'addMonth' : 'addYear'}();

        return $first->day(min($this->due_day, $first->daysInMonth));
    }

    /** Resuming skips paused periods: next_due_on moves to the first anchored date >= today. */
    public function setActive(bool $active, CarbonInterface $today): void
    {
        if ($active && ! $this->active) {
            while ($this->next_due_on->lt($today->copy()->startOfDay())) {
                $this->next_due_on = $this->advance($this->next_due_on);
            }
        }
        $this->active = $active;
        $this->save();
    }

    /** Creates a pending line per due period up to $today (max 36 per run, rest next run), advancing one period each time. Returns lines created. */
    public function generateDue(CarbonInterface $today): int
    {
        $created = 0;
        for ($i = 0; $i < self::MAX_PERIODS_PER_RUN && $this->active && $this->next_due_on->lte($today); $i++) {
            $due = $this->next_due_on;
            $monthly = $this->frequency === RecurringFrequency::Monthly;
            $line = BillableLine::createOrFirst(
                ['recurring_charge_id' => $this->id, 'period' => $due->format($monthly ? 'Y-m' : 'Y')],
                ['client_id' => $this->client_id, 'project_id' => $this->project_id, 'description' => $this->description,
                    'quantity' => 1, 'unit_price_cents' => $this->amount_cents, 'amount_cents' => $this->amount_cents],
            );
            $created += (int) $line->wasRecentlyCreated;

            $this->next_due_on = $this->advance($due);
            $this->save();
        }

        return $created;
    }
}

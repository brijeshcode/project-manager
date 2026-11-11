<?php

namespace App\Models;

use App\Enums\RecurringFrequency;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use App\Models\Concerns\FreezesLocalAmounts;
use App\Services\LocalCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class RecurringCharge extends Model
{
    use FreezesLocalAmounts;

    protected $fillable = ['client_id', 'project_id', 'description', 'amount_cents', 'exchange_rate', 'local_amount_cents', 'frequency', 'next_due_on', 'due_day', 'active'];

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

    protected function localAmounts(): array
    {
        return ['amount_cents' => ['local_amount_cents', 'cents']];
    }

    protected function moneyCurrency(): ?string
    {
        return $this->project?->currency ?? $this->client?->currency;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillableLine::class);
    }

    /** Currency the charge and its lines are priced in: its project's, else the client's. */
    public function currency(): string
    {
        return $this->moneyCurrency() ?? LocalCurrency::code();
    }

    /** Adds invoiced_count / invoiced_cents / invoiced_local_cents (lines on sent invoices) and draft_count (lines on not-sent invoices). Voiding releases lines, so void never counts. */
    public function scopeWithUsage(Builder $q): Builder
    {
        $on = fn (string $status) => fn ($l) => $l->whereHas('invoice', fn ($i) => $i->where('status', $status));

        return $q->withCount(['lines as invoiced_count' => $on('sent'), 'lines as draft_count' => $on('draft')])
            ->withSum(['lines as invoiced_cents' => $on('sent')], 'amount_cents')
            ->withSum(['lines as invoiced_local_cents' => $on('sent')], 'local_amount_cents');
    }

    public const KEEP_HISTORY = 'This service is on invoices, so its history is kept. Pause it instead.';

    /** Deletes the charge and its pending lines; refuses (false) once any of its lines is on an invoice. */
    public function deleteIfNeverInvoiced(): bool
    {
        return DB::transaction(function () {
            if ($this->lines()->whereNotNull('invoice_id')->lockForUpdate()->exists()) {
                return false;
            }
            $this->lines()->delete();
            $this->delete();

            return true;
        });
    }

    /** Value per month in the local currency (yearly spread over 12). */
    public function monthlyLocalCents(): int
    {
        return intdiv((int) $this->local_amount_cents, $this->frequency === RecurringFrequency::Yearly ? 12 : 1);
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

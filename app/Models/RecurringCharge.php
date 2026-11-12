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

    protected $fillable = ['client_id', 'project_id', 'description', 'amount_cents', 'exchange_rate', 'local_amount_cents', 'frequency', 'next_due_on', 'due_day', 'active',
        'client_state', 'client_note', 'client_approved_at', 'cancel_note', 'cancel_requested_at', 'ended_at', 'skipped_periods', 'resume_on', 'pass_through'];

    public const AWAITING = 'awaiting';
    public const CANCEL_REQUESTED = 'cancel_requested';
    public const ENDED = 'ended';

    protected function casts(): array
    {
        return ['frequency' => RecurringFrequency::class, 'next_due_on' => 'date', 'active' => 'boolean',
            'client_approved_at' => 'datetime', 'cancel_requested_at' => 'datetime', 'ended_at' => 'datetime',
            'skipped_periods' => 'array', 'resume_on' => 'date', 'pass_through' => 'boolean'];
    }

    /** [label, badge colour] for every list and page. */
    public function statusBadge(): array
    {
        return match (true) {
            $this->client_state === self::AWAITING => ['Awaiting client approval', 'amber'],
            $this->client_state === self::CANCEL_REQUESTED => ['Cancellation requested', 'rose'],
            $this->client_state === self::ENDED => ['Ended', 'slate'],
            $this->active => ['Active', 'green'],
            $this->resume_on !== null => ['Paused until '.$this->resume_on->toFormattedDateString(), 'amber'],
            default => ['Paused', 'slate'],
        };
    }

    /** The client accepts a new subscription; it starts now. */
    public function approveByClient(string $note): void
    {
        abort_unless($this->client_state === self::AWAITING, 422);
        $this->fill(['client_state' => null, 'client_note' => $note, 'client_approved_at' => now()]);
        $this->setActive(true, today());
    }

    /** The client asks to stop; it keeps running until the team approves. */
    public function requestCancel(string $note): void
    {
        abort_unless($this->active && $this->client_state === null, 422);
        $this->update(['client_state' => self::CANCEL_REQUESTED, 'cancel_note' => $note, 'cancel_requested_at' => now()]);
    }

    /** Team decision on a cancellation request: approve ends the subscription, reject keeps it running. */
    public function decideCancel(bool $approve): void
    {
        abort_unless($this->client_state === self::CANCEL_REQUESTED, 422);
        $approve
            ? $this->update(['client_state' => self::ENDED, 'active' => false, 'ended_at' => now()])
            : $this->update(['client_state' => null]);
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

    /** Value per month in the service's own currency (yearly spread over 12). */
    public function monthlyCents(): int
    {
        return intdiv((int) $this->amount_cents, $this->frequency === RecurringFrequency::Yearly ? 12 : 1);
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
            $this->skipPast($today);
        }
        $this->active = $active;
        $this->resume_on = null; // a plain pause or resume drops any "paused until" date
        if ($active) {
            $this->client_state = null; // the team starting or resuming it settles any approval or ended state
        }
        $this->save();
    }

    /** Moves next_due_on to the first anchored date >= $today without billing the periods passed over. Does not save. */
    private function skipPast(CarbonInterface $today): void
    {
        while ($this->next_due_on->lt($today->copy()->startOfDay())) {
            $this->next_due_on = $this->advance($this->next_due_on);
        }
    }

    private function periodOf(CarbonInterface $due): string
    {
        return $due->format($this->frequency === RecurringFrequency::Monthly ? 'Y-m' : 'Y');
    }

    /** How many periods are already due on or before $today (for the "add past periods" hint). */
    public function pastPeriods(CarbonInterface $today): int
    {
        $n = 0;
        for ($d = Carbon::parse($this->next_due_on); $d->lte($today) && $n < 1000; $d = $this->advance($d)) {
            $n++;
        }

        return $n;
    }

    /** A service the client already agreed to: active now, approved by the team; past periods billed as pending lines or skipped. */
    public function startApproved(bool $withPastPeriods, CarbonInterface $today): void
    {
        $this->forceFill(['active' => true, 'client_state' => null, 'client_approved_at' => now()]);
        if (! $withPastPeriods) {
            $this->skipPast($today);
        }
        $this->save();
        while ($withPastPeriods && $this->generateDue($today) > 0) {
            // generateDue caps each run at 36 periods; keep going until caught up
        }
    }

    /** Passes over the next $n periods without billing them, and remembers which ones. Active services only. */
    public function skip(int $n): void
    {
        abort_unless($this->active && $n >= 1 && $n <= 12, 422);
        $skipped = $this->skipped_periods ?? [];
        for ($i = 0; $i < $n; $i++) {
            $skipped[] = $this->periodOf($this->next_due_on);
            $this->next_due_on = $this->advance($this->next_due_on);
        }
        $this->skipped_periods = $skipped;
        $this->save();
    }

    /** Pauses now; the daily run resumes it on $date, skipping (not billing) the paused periods. */
    public function pauseUntil(CarbonInterface $date): void
    {
        abort_unless($date->gt(today()), 422);
        $this->setActive(false, today());
        $this->resume_on = $date->toDateString();
        $this->save();
    }

    /** Resumes every service whose pause has run out. Returns how many resumed. */
    public static function resumeDue(CarbonInterface $today): int
    {
        $n = 0;
        static::where('active', false)->whereNotNull('resume_on')->whereDate('resume_on', '<=', $today)->each(function (self $c) use ($today, &$n) {
            $c->setActive(true, $today);
            $n++;
        });

        return $n;
    }

    /** Creates a pending line per due period up to $today (max 36 per run, rest next run), advancing one period each time. Returns lines created. */
    public function generateDue(CarbonInterface $today): int
    {
        $created = 0;
        for ($i = 0; $i < self::MAX_PERIODS_PER_RUN && $this->active && $this->next_due_on->lte($today); $i++) {
            $due = $this->next_due_on;
            $line = BillableLine::createOrFirst(
                ['recurring_charge_id' => $this->id, 'period' => $this->periodOf($due)],
                ['client_id' => $this->client_id, 'project_id' => $this->project_id, 'description' => $this->description,
                    'quantity' => 1, 'unit_price_cents' => $this->amount_cents, 'amount_cents' => $this->amount_cents, 'pass_through' => (bool) $this->pass_through],
            );
            $created += (int) $line->wasRecentlyCreated;

            $this->next_due_on = $this->advance($due);
            $this->save();
        }

        return $created;
    }
}

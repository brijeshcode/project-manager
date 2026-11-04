<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TimeEntry extends Model
{
    protected $fillable = [
        'timeable_type',
        'timeable_id',
        'user_id',
        'hours',
        'started_at',
        'completed_at',
        'worked_on',
    ];

    protected function casts(): array
    {
        return [
            'hours'        => 'float',
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'worked_on'    => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        // an hourly task's value follows the logged hours (never blocked: the module panel shows any overspend)
        $sync = fn (self $e) => ($t = $e->timeable instanceof Subtask ? $e->timeable->task : $e->timeable) instanceof Task && Task::find($t->id)?->recalculateValue();
        static::saved($sync);
        static::deleted($sync);
    }

    public function timeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Derive cost from the logging user's rate — never typed in.
     *
     * - Hourly: hours × cost_rate_amount.
     * - Flat:   the user's fixed cost_rate_amount, returned as-is per entry.
     *
     * ponytail: flat rate de-duplication across a project's entries (once-per-project)
     * is the caller's responsibility. derivedCost() returns the flat amount for each
     * individual entry; aggregation code must group by user and count the flat amount
     * only once. This keeps the model simple — the "once per project" constraint is a
     * reporting/aggregation concern, not a per-entry property.
     */
    public function derivedCost(): float
    {
        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();

        // ponytail: null guard for deleted users; returns 0.0 to match User::costFor null tolerance
        if ($user === null) {
            return 0.0;
        }

        return $user->costFor($this->hours);
    }

    /**
     * Scope: entries where worked_on falls within [$from, $to] inclusive.
     * Used for today/week/month hour totals.
     */
    public function scopeLoggedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('worked_on', [$from, $to]);
    }
}

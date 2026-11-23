<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CostRateType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'client_id',
        'cost_rate_type',
        'cost_rate_amount',
        'weekly_capacity_hours',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'cost_rate_type' => CostRateType::class,
            'cost_rate_amount' => 'float',
            'weekly_capacity_hours' => 'float',
            'active' => 'boolean',
        ];
    }

    /**
     * Remaining hours of this user's open (not done) counted tasks across every project's active sprint, against
     * their weekly capacity scaled to the active window. Capacity null = no limit (never over).
     */
    public function sprintLoad(): array
    {
        $sprints = Sprint::where('status', 'active')->get();
        $tasks = Task::counted()->whereIn('sprint_id', $sprints->pluck('id'))
            ->whereHas('assignees', fn ($q) => $q->whereKey($this->id))
            ->with(['column', 'subtasks'])->get()
            ->reject(fn (Task $t) => $t->column?->stage === 'done');
        $committed = Sprint::hours($tasks);

        // ponytail: capacity/week × the longest remaining active-sprint span (min 1 week); per-day calendars if needed
        $weeks = max(1, $sprints->max(fn (Sprint $s) => today()->diffInDays($s->ends_on, false) + 1) / 7);
        $capacity = $this->weekly_capacity_hours === null ? null : round($this->weekly_capacity_hours * $weeks, 2);

        return ['committed' => $committed, 'capacity' => $capacity, 'over' => $capacity !== null && $committed > $capacity];
    }

    /** Whether adding `$extraHours` to the current sprint load would go past capacity. */
    public function wouldExceedCapacity(float $extraHours): bool
    {
        $load = $this->sprintLoad();

        return $load['capacity'] !== null && $load['committed'] + $extraHours > $load['capacity'];
    }

    public function projects(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->wherePivot('active', true)->wherePivotNull('removed_at');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Derive cost for a given number of hours based on this user's rate type.
     * Hourly: hours × rate. Flat: the fixed amount (hours ignored).
     * Null/unconfigured rate type: returns 0.0 (no cost).
     */
    public function costFor(float $hours): float
    {
        return match ($this->cost_rate_type) {
            CostRateType::Hourly => $hours * (float) $this->cost_rate_amount,
            CostRateType::Flat   => (float) $this->cost_rate_amount,
            null                 => 0.0,
        };
    }
}

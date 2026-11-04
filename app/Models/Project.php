<?php

namespace App\Models;

use App\Services\EstimateService;
use App\Support\BoardTemplates;
use Carbon\CarbonInterface;
use App\Models\Concerns\FreezesLocalAmounts;
use App\Models\Concerns\HasCurrency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Project extends Model
{
    use HasCurrency, FreezesLocalAmounts;

    protected $attributes = ['initial_budget' => 0, 'addons_amount' => 0, 'final_budget' => 0];

    protected $fillable = [
        'aim',
        'initial_budget',
        'addons_amount',
        'client_id',
        'currency',
        'name',
        'description',
        'buffer_percent',
        'start_date',
        'status',
        'template',
        'completed_at',
    ];

    protected static function booted(): void
    {
        // a project starts in its client's currency; the owner can change it
        static::creating(function (self $p) {
            $p->currency_id ??= Client::whereKey($p->client_id)->value('currency_id');
        });

        // the chosen board template (default software) becomes this project's own, editable columns
        static::created(function (self $p) {
            foreach (BoardTemplates::columns($p->template) as $i => [$name, $stage]) {
                $p->columns()->create(['name' => $name, 'stage' => $stage, 'position' => $i + 1]);
            }
        });

        // completed_at: stamped when the project becomes completed, cleared when it leaves
        static::saving(function (self $p) {
            $p->completed_at = $p->status === 'completed' ? ($p->completed_at ?? today()) : null;
        });

        // runs after the trait's saving hook, so the local amounts are already fresh
        static::saving(function (self $p) {
            $sum = fn ($a, $b) => Money::toInput(Money::toCents($a ?? 0) + Money::toCents($b ?? 0));
            $p->final_budget = $sum($p->initial_budget, $p->addons_amount);
            $p->final_budget_local = $sum($p->initial_budget_local, $p->addons_amount_local);
        });
    }

    protected function localAmounts(): array
    {
        return ['initial_budget' => ['initial_budget_local', 'major'], 'addons_amount' => ['addons_amount_local', 'major']];
    }

    protected function moneyCurrency(): ?string
    {
        // on create the saving hook runs before the creating hook has defaulted currency_id
        return $this->currency_id ? $this->currency : Client::find($this->client_id)?->currency;
    }

    protected function casts(): array
    {
        return [
            'start_date'     => 'date', 'deadline' => 'date', 'completed_at' => 'date',
            'buffer_percent' => 'decimal:2',
            'initial_budget' => 'decimal:2', 'initial_budget_local' => 'decimal:2',
            'addons_amount' => 'decimal:2', 'addons_amount_local' => 'decimal:2',
            'final_budget' => 'decimal:2', 'final_budget_local' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
                    ->withPivot(['role_on_project', 'access_scope', 'active', 'removed_at'])
                    ->withTimestamps();
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class)->orderBy('starts_on')->orderBy('id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Calculated completion date from EstimateService (the manual target is the `deadline` attribute). */
    public function estimatedCompletion(): CarbonInterface
    {
        return app(EstimateService::class)->projectDeadline($this);
    }

    public function deadlineChanges(): HasMany
    {
        return $this->hasMany(ProjectDeadlineChange::class)->latest('id');
    }

    /** Sets the manual deadline and logs it. First-ever set needs no reason; changing an existing one does. */
    public function setDeadline($date, User $by, ?string $reason = null): void
    {
        $new = \Illuminate\Support\Carbon::parse($date)->startOfDay();
        $old = $this->deadline;
        if ($old?->equalTo($new)) {
            return;
        }
        if ($old && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when changing the deadline.']);
        }

        DB::transaction(function () use ($old, $new, $by, $reason) {
            $this->deadlineChanges()->create(['user_id' => $by->id, 'old_deadline' => $old, 'new_deadline' => $new, 'reason' => trim((string) $reason) ?: null]);
            $this->deadline = $new;
            $this->save();
        });
    }

    /** Times the deadline was pushed later (the initial set doesn't count). */
    public function extensionCount(): int
    {
        return $this->deadlineChanges()->get()->filter(fn ($c) => $c->old_deadline && $c->new_deadline->gt($c->old_deadline))->count();
    }

    /** [label, badge colour] for the deadline card, or null when no deadline is set. */
    public function deadlineStatus(): ?array
    {
        if (! $this->deadline) {
            return null;
        }
        $days = fn (int $n) => $n.' '.($n === 1 ? 'day' : 'days');

        if ($this->status === 'completed' && $this->completed_at) {
            $early = (int) $this->completed_at->diffInDays($this->deadline, false);   // > 0 = finished before the deadline

            return $early > 0 ? ['Finished '.$days($early).' early', 'green']
                : ($early === 0 ? ['Finished on time', 'green'] : ['Finished '.$days(-$early).' late', 'rose']);
        }
        if (today()->gt($this->deadline)) {
            return ['Overdue', 'rose'];
        }
        $late = (int) $this->deadline->diffInDays($this->estimatedCompletion()->startOfDay(), false);

        return $late > 0 ? ['At risk — estimate is '.$days($late).' past deadline', 'amber'] : ['On track', 'green'];
    }

    /**
     * Time progress bar: total logged hours ÷ total estimated hours.
     * Returns 0.0 when there are no estimated hours (divide-by-zero guard).
     */
    public function timeBar(): float
    {
        ['estimated' => $estimated, 'logged' => $logged] = $this->estimateVsActual();

        if ($estimated <= 0.0) {
            return 0.0;
        }

        return $logged / $estimated;
    }

    /**
     * Returns ['estimated' => float, 'logged' => float].
     * Estimated = sum of all tasks' totalEstimateHours across all modules.
     * Logged    = sum of all time entry hours on those tasks and their subtasks.
     */
    public function estimateVsActual(): array
    {
        $this->loadMissing('modules.tasks.subtasks.timeEntries', 'modules.tasks.timeEntries');

        $estimated = 0.0;
        $logged    = 0.0;

        foreach ($this->modules as $module) {
            foreach ($module->tasks as $task) {
                $estimated += $task->totalEstimateHours();
                $logged    += $task->timeEntries->sum('hours');
                foreach ($task->subtasks as $subtask) {
                    $logged += $subtask->timeEntries->sum('hours');
                }
            }
        }

        return ['estimated' => $estimated, 'logged' => $logged];
    }
}

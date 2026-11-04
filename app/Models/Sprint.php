<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A time-boxed batch of a project's tasks. One ACTIVE sprint per project; any number planned. */
class Sprint extends Model
{
    /** Two weeks plus a small tolerance (days between starts_on and ends_on). */
    public const MAX_DAYS = 16;

    protected $fillable = ['project_id', 'name', 'goal', 'starts_on', 'ends_on', 'status', 'completed_at', 'planned_value_cents', 'done_value_cents', 'carried_value_cents'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'completed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopePlanned(Builder $q): Builder
    {
        return $q->where('status', 'planned');
    }

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->where('status', 'completed');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Whole days from today to the end date (0 = ends today, negative = overdue). */
    public function daysLeft(): int
    {
        return (int) Carbon::today()->diffInDays($this->ends_on->copy()->startOfDay(), false);
    }

    /** "Sprint N" for the project's next sprint: one above the highest number already used. */
    public static function nextName(int $projectId): string
    {
        $max = static::where('project_id', $projectId)->pluck('name')
            ->map(fn ($n) => preg_match('/(\d+)\s*$/', $n, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return 'Sprint '.($max + 1);
    }

    /** Throws a ValidationException (key `ends_on`) unless the dates make a 1-2 week sprint. */
    public static function checkDates(Carbon|string $starts, Carbon|string $ends): void
    {
        $starts = Carbon::parse($starts)->startOfDay();
        $ends = Carbon::parse($ends)->startOfDay();
        if ($ends->lte($starts)) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be after the start date.']);
        }
        if ($starts->diffInDays($ends) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['ends_on' => 'A sprint lasts one or two weeks at most.']);
        }
    }

    /** Hours of a set of tasks (loaded `subtasks` expected): own effective hours plus subtasks, the same weight progress uses. */
    public static function hours(Collection $tasks): float
    {
        return round($tasks->sum(fn (Task $t) => $t->hours()), 2);
    }

    /** Counted tasks with their stage loaded: all, done (in a done-stage column) and open (the rest). */
    public function summary(): array
    {
        $all = $this->tasks()->counted()->with(['column', 'subtasks', 'module.project.client'])->get();
        $done = $all->filter(fn (Task $t) => $t->column?->stage === 'done')->values();

        return ['all' => $all, 'done' => $done, 'open' => $all->diff($done)->values()];
    }

    /** Money (cents) of a set of tasks: each task's stored value (typed when fixed, billed hours x rate when hourly). */
    public static function value(Collection $tasks): int
    {
        return $tasks->sum(fn (Task $t) => $t->budget_amount === null ? 0 : Money::toCents((string) $t->budget_amount));
    }

    /** planned -> active. Only one sprint per project may be active. */
    public function start(): void
    {
        DB::transaction(function () {
            if ($this->status !== 'planned') {
                throw ValidationException::withMessages(['sprint' => 'Only a planned sprint can be started.']);
            }
            if (static::where('project_id', $this->project_id)->active()->exists()) {
                throw ValidationException::withMessages(['sprint' => 'This project already has an active sprint. Complete it first.']);
            }
            $this->update(['status' => 'active']);
        });
    }

    /**
     * active -> completed. Done tasks stay linked to this sprint. Unfinished tasks go to the next planned sprint
     * ($to = 'next'; one is created, named and dated after this one, when none is planned) or back to the backlog.
     * Returns the sprint they moved to, if any.
     */
    public function complete(string $to): ?self
    {
        return DB::transaction(function () use ($to) {
            if ($this->status !== 'active') {
                throw ValidationException::withMessages(['sprint' => 'Only the active sprint can be completed.']);
            }
            $next = null;
            $sum = $this->summary();
            $open = $sum['open'];
            if ($to === 'next' && $open->isNotEmpty()) {
                $next = static::where('project_id', $this->project_id)->planned()->orderBy('starts_on')->orderBy('id')->first()
                    ?? static::create([
                        'project_id' => $this->project_id, 'name' => static::nextName($this->project_id), 'status' => 'planned',
                        'starts_on' => $start = $this->ends_on->copy()->addDay(),
                        'ends_on' => $start->copy()->addDays(max(1, $this->starts_on->diffInDays($this->ends_on))),
                    ]);
            }
            foreach ($open as $task) { // per model: each move is logged
                $task->update(['sprint_id' => $next?->id]);
            }
            $this->update(['status' => 'completed', 'completed_at' => now(), 'planned_value_cents' => static::value($sum['all']), 'done_value_cents' => static::value($sum['done']), 'carried_value_cents' => static::value($open)]);
            // the done tasks become ONE billable line for the sprint (replacing their pending per-task lines)
            app(\App\Services\BillableService::class)->syncCompletedWork($this->project->client);

            return $next;
        });
    }

    /** Planned sprints can go; their tasks return to the backlog. */
    public function deletePlanned(): void
    {
        DB::transaction(function () {
            if ($this->status !== 'planned') {
                throw ValidationException::withMessages(['sprint' => 'Only a planned sprint can be deleted.']);
            }
            $this->tasks()->get()->each->update(['sprint_id' => null]);
            $this->delete();
        });
    }
}

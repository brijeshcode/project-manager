<?php

namespace App\Models;

use App\Services\EstimateService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'description',
        'buffer_percent',
        'start_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date'     => 'date',
            'buffer_percent' => 'decimal:2',
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

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)->orderBy('position');
    }

    public function currency(): string
    {
        return $this->client->currency;
    }

    /** Delegates deadline computation to EstimateService. */
    public function deadline(): CarbonInterface
    {
        return app(EstimateService::class)->projectDeadline($this);
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

<?php

namespace App\Livewire\Concerns;

use App\Models\Task;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/** The Modules tab filter (In active sprint / Not in sprint / Completed / All) for `$this->project`. */
trait FiltersModules
{
    /** Module list filter: all | sprint | backlog | completed. */
    #[Url(as: 'filter')]
    public string $filter = 'all';

    /**
     * View data for the module list: mainRows, doneRows (the Completed section, under All only), filter, activeSprint.
     * Sprint = modules with at least one task in the ACTIVE sprint; Backlog = open modules without one. $keep narrows the rows.
     */
    protected function moduleRows(Collection $modules, ?\Closure $keep = null): array
    {
        $activeSprint = $this->project->sprints()->active()->first();
        $sprintModules = $activeSprint ? Task::counted()->where('sprint_id', $activeSprint->id)->pluck('module_id')->unique() : collect();
        $filter = in_array($this->filter, ['all', 'sprint', 'backlog', 'completed'], true) ? $this->filter : 'all';
        $rows = $modules->map(fn ($m) => ['m' => $m, 'status' => $m->status()])->filter($keep ?? fn () => true)->values();
        $done = $rows->filter(fn ($r) => $r['status'] === 'completed')->values();
        $open = $rows->reject(fn ($r) => $r['status'] === 'completed')->values();

        return [
            'mainRows' => match ($filter) {
                'completed' => $done,
                'sprint' => $rows->filter(fn ($r) => $sprintModules->contains($r['m']->id))->values(),
                'backlog' => $open->reject(fn ($r) => $sprintModules->contains($r['m']->id))->values(),
                default => $open,
            },
            'doneRows' => $filter === 'all' ? $done : collect(),
            'filter' => $filter,
            'activeSprint' => $activeSprint,
        ];
    }
}

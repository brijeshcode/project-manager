<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * The ONE progress rule for tasks, modules and projects (0..100).
 *
 * Task steps = checklist items + subtasks + 1 extra "moved to Done" step.
 *   done = ticked items + done subtasks (is_done) + 1 when the task sits in a done-stage column.
 * A task in a done-stage column is always 100%. No items and not done = 0%.
 * A subtask counts as ONE step (its is_done); the subtask's own checklist items are not pooled in.
 * Example: 2 checklist items, both ticked, task not in Done = 2/3 = 66.67%.
 *
 * Module = hours-weighted average of its counted tasks' progress (same task rule), plain average when no
 * task has hours. Project = the same weighting over its modules.
 */
class ProgressService
{
    public function forTask(Task $task): float
    {
        if ($task->column?->stage === 'done') {
            return 100.0;
        }

        $items = $task->checklistItems;
        $subtasks = $task->subtasks;
        $total = $items->count() + $subtasks->count() + 1;
        $done = $items->where('is_done', true)->count() + $subtasks->where('is_done', true)->count();

        // nothing to tick and not in Done yet
        return $total === 1 ? 0.0 : round($done / $total * 100, 10);
    }

    public function forModule(Module $module): float
    {
        return $this->ofTasks($module->countedTasks(['column', 'checklistItems', 'subtasks']));
    }

    /** Constant number of queries however many modules the project has. */
    public function forProject(Project $project): float
    {
        $modules = $project->modules()->with(['tasks' => fn ($q) => $q->counted()->with(['column', 'checklistItems', 'subtasks'])])->get();

        return $this->weighted(
            $modules,
            fn (Module $m) => $m->tasks->sum(fn (Task $t) => $this->hours($t)),
            fn (Module $m) => $this->ofTasks($m->tasks),
        );
    }

    private function ofTasks(Collection $tasks): float
    {
        return $this->weighted($tasks, fn (Task $t) => $this->hours($t), fn (Task $t) => $this->forTask($t));
    }

    /** Task weight: own effective hours plus its subtasks' hours (uses the loaded relation, no extra query). */
    private function hours(Task $task): float
    {
        return $task->effectiveHours() + (float) $task->subtasks->sum('estimate_hours');
    }

    /** Hours-weighted average of value(); a plain average when nothing has hours; 0 for an empty set. */
    private function weighted(Collection $items, callable $weight, callable $value): float
    {
        if ($items->isEmpty()) {
            return 0.0;
        }
        $rows = $items->map(fn ($i) => [$weight($i), $value($i)]);
        $total = $rows->sum(0);

        return $total > 0
            ? round($rows->sum(fn ($r) => $r[0] * $r[1]) / $total, 10)
            : round($rows->avg(1), 10);
    }
}

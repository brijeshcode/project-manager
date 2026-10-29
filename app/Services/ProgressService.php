<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Project;
use App\Models\Task;

class ProgressService
{
    /**
     * Task progress: flat pool of ALL checklist items across the task itself
     * AND all of its subtasks. done ÷ total × 100.
     *
     * Rationale: a subtask is just another slice of work on the same task;
     * treating every checklist item uniformly avoids needing weights between
     * the task-level items and the subtask-level items, and keeps the math simple.
     *
     * Guard: zero items → 0.0 (never divides by zero).
     */
    public function forTask(Task $task): float
    {
        $taskItems    = $task->checklistItems;
        $subtaskItems = $task->subtasks()
            ->with('checklistItems')
            ->get()
            ->flatMap(fn ($st) => $st->checklistItems);

        $all   = $taskItems->merge($subtaskItems);
        $total = $all->count();

        if ($total === 0) {
            return 0.0;
        }

        return round($all->where('is_done', true)->count() / $total * 100, 10);
    }

    /**
     * Module progress: hours-weighted average of its tasks' progress.
     * Weight for each task = totalEstimateHours() (task's effectiveHours + subtask hours).
     *
     * Guard: if all tasks have 0 totalEstimateHours → returns 0.0.
     * ponytail: 0-hour tasks contribute 0 weight; a module of all-zero-hour tasks
     *           returns 0.0 rather than an unweighted average — documented choice.
     */
    public function forModule(Module $module): float
    {
        $tasks = $module->tasks()->with(['checklistItems', 'subtasks.checklistItems'])->get();

        if ($tasks->isEmpty()) {
            return 0.0;
        }

        $totalWeight    = 0.0;
        $weightedSum    = 0.0;

        foreach ($tasks as $task) {
            $weight = $task->totalEstimateHours();
            if ($weight <= 0.0) {
                continue; // ponytail: skip 0-hour tasks from weighting
            }
            $totalWeight += $weight;
            $weightedSum += $weight * $this->forTask($task);
        }

        if ($totalWeight === 0.0) {
            return 0.0;
        }

        return round($weightedSum / $totalWeight, 10);
    }

    /**
     * Project progress: hours-weighted average of its modules' progress.
     * Module weight = sum of its tasks' totalEstimateHours.
     *
     * Consistent with forModule — same weighting strategy one level up.
     */
    public function forProject(Project $project): float
    {
        $modules = $project->modules()
            ->with(['tasks.checklistItems', 'tasks.subtasks.checklistItems'])
            ->get();

        if ($modules->isEmpty()) {
            return 0.0;
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;

        foreach ($modules as $module) {
            $weight = $module->tasks->sum(fn ($t) => $t->totalEstimateHours());
            if ($weight <= 0.0) {
                continue;
            }
            $totalWeight += $weight;
            $weightedSum += $weight * $this->forModule($module);
        }

        if ($totalWeight === 0.0) {
            return 0.0;
        }

        return round($weightedSum / $totalWeight, 10);
    }
}

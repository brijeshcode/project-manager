<?php

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    use ResolvesAbility;

    /**
     * View: published tasks open to any project member;
     * drafts visible only to their creator.
     * Note: Gate::before returns true for super_admin, so super_admin bypasses
     * this check for published tasks. Draft hiding is enforced additionally
     * at the query scope level (Task::visibleTo) for list/board queries.
     *
     * INTENTIONAL: view is NOT subject to per-ability PermissionOverrides.
     * Task visibility is governed by project membership and the draft creator-only rule
     * (enforced via Task::visibleTo at query level). Overrides apply only to action
     * abilities (update, updateCostTime, comment) — wiring an override into view could
     * allow a non-member to resolve another user's draft task via a single-model Gate check.
     */
    public function view(User $user, Task $task): bool
    {
        if ($task->status === TaskStatus::Draft) {
            return $task->creator_id === $user->id;
        }

        return true;
    }

    /**
     * Update: super_admin/PM always; assignees (any role) may update general fields.
     * Cost/time field changes are gated separately via updateCostTime.
     */
    public function update(User $user, Task $task): bool
    {
        $project  = $task->module->project;
        $resolved = $this->resolvesAbility($user, $project, 'update');
        if ($resolved !== null) {
            return $resolved;
        }

        if ($user->hasRole(['project_manager'])) {
            return true;
        }

        return $task->assignees()->where('users.id', $user->id)->exists();
    }

    /**
     * updateCostTime: blocked for developers; allowed for PM and other assignees.
     * super_admin bypasses via Gate::before.
     */
    public function updateCostTime(User $user, Task $task): bool
    {
        $project  = $task->module->project;
        $resolved = $this->resolvesAbility($user, $project, 'updateCostTime');
        if ($resolved !== null) {
            return $resolved;
        }

        if ($user->hasRole('developer')) {
            return false;
        }

        if ($user->hasRole('project_manager')) {
            return true;
        }

        return $task->assignees()->where('users.id', $user->id)->exists();
    }

    /**
     * Comment: any assignee (including developer).
     * super_admin bypasses via Gate::before.
     */
    public function comment(User $user, Task $task): bool
    {
        $project  = $task->module->project;
        $resolved = $this->resolvesAbility($user, $project, 'comment');
        if ($resolved !== null) {
            return $resolved;
        }

        return $task->assignees()->where('users.id', $user->id)->exists();
    }
}

<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole('project_manager');
    }

    public function view(User $user, Project $project): bool
    {
        return $this->isMember($user, $project);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->isMember($user, $project);
    }

    private function isMember(User $user, Project $project): bool
    {
        return $project->members()
            ->where('users.id', $user->id)
            ->where('project_members.active', true)
            ->exists();
    }
}

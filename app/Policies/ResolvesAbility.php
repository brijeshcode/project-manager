<?php

namespace App\Policies;

use App\Models\PermissionOverride;
use App\Models\Project;
use App\Models\User;

/**
 * Shared resolution order for per-user/per-project ability overrides.
 *
 * Order: super_admin → explicit PermissionOverride → role default (caller).
 *
 * Usage: include in a policy and call resolvesAbility($user, $project, $ability)
 * before the role-default logic. Return its value when it is non-null; fall
 * through to the role default when null is returned.
 */
trait ResolvesAbility
{
    /**
     * Returns true/false when an explicit ruling exists, or null to fall through
     * to the caller's role-default logic.
     *
     * Note: super_admin is handled by Gate::before globally; we still short-circuit
     * here so the trait is self-contained when used outside that Gate context.
     */
    protected function resolvesAbility(User $user, Project $project, string $ability): ?bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $override = PermissionOverride::where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->where('ability', $ability)
            ->first();

        return $override?->allowed; // true, false, or null if no record
    }
}

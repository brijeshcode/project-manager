<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Module;
use App\Models\PermissionOverride;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers ───────────────────────────────────────────────────────────────────

function overrideProject(): Project
{
    $client = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
}

function overrideMember(Project $project, string $role = 'developer', AccessScope $scope = AccessScope::All): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => $role,
        'access_scope'    => $scope,
        'active'          => true,
    ]);
    return $user;
}

function overrideModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Core', 'position' => 1]);
}

function overrideTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'Test Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::In,
        'board_column'   => 'Backlog',
        'position'       => 1,
    ], $attrs));
}

// ── resolvesAbility: override GRANTS extra ability ────────────────────────────

it('developer granted updateCostTime override gains the ability', function () {
    $project = overrideProject();
    $module  = overrideModule($project);
    $dev     = overrideMember($project, 'developer');
    $task    = overrideTask($module, $dev);
    $task->assignees()->attach($dev->id);

    // Before override: developer is denied
    expect($dev->can('updateCostTime', $task))->toBeFalse();

    // Grant via override
    PermissionOverride::create([
        'user_id'    => $dev->id,
        'project_id' => $project->id,
        'ability'    => 'updateCostTime',
        'allowed'    => true,
    ]);

    // Refresh gate resolution
    app()[\Illuminate\Contracts\Auth\Access\Gate::class]->forUser($dev)->abilities();
    expect($dev->fresh()->can('updateCostTime', $task))->toBeTrue();
});

it('developer REVOKED updateCostTime override loses it even as PM-level assignee', function () {
    $project = overrideProject();
    $module  = overrideModule($project);
    $pm      = overrideMember($project, 'project_manager');
    $task    = overrideTask($module, $pm);
    $task->assignees()->attach($pm->id);

    // Before override: PM is allowed
    expect($pm->can('updateCostTime', $task))->toBeTrue();

    // Revoke via override
    PermissionOverride::create([
        'user_id'    => $pm->id,
        'project_id' => $project->id,
        'ability'    => 'updateCostTime',
        'allowed'    => false,
    ]);

    expect($pm->fresh()->can('updateCostTime', $task))->toBeFalse();
});

// ── resolvesAbility: super_admin wins over explicit deny override ─────────────

it('super_admin wins over an explicit deny override', function () {
    $project    = overrideProject();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $module = overrideModule($project);
    $member = overrideMember($project, 'developer');
    $task   = overrideTask($module, $member);

    // Create an explicit deny for super_admin (should be ignored)
    PermissionOverride::create([
        'user_id'    => $superAdmin->id,
        'project_id' => $project->id,
        'ability'    => 'updateCostTime',
        'allowed'    => false,
    ]);

    // super_admin bypasses all gates
    expect($superAdmin->can('updateCostTime', $task))->toBeTrue();
});

// ── resolvesAbility: ProjectPolicy view/update ────────────────────────────────

it('non-member granted view override can view the project', function () {
    $project = overrideProject();
    $user    = User::factory()->create();
    $user->assignRole('developer');

    // Before override: non-member denied
    expect($user->can('view', $project))->toBeFalse();

    PermissionOverride::create([
        'user_id'    => $user->id,
        'project_id' => $project->id,
        'ability'    => 'view',
        'allowed'    => true,
    ]);

    expect($user->fresh()->can('view', $project))->toBeTrue();
});

it('member with revoked view override is denied project view', function () {
    $project = overrideProject();
    $member  = overrideMember($project, 'developer');

    // Before override: member can view
    expect($member->can('view', $project))->toBeTrue();

    PermissionOverride::create([
        'user_id'    => $member->id,
        'project_id' => $project->id,
        'ability'    => 'view',
        'allowed'    => false,
    ]);

    expect($member->fresh()->can('view', $project))->toBeFalse();
});

// ── section access: frontend scope ───────────────────────────────────────────

it('member with access_scope=frontend sees frontend-sectioned and null-sectioned tasks', function () {
    $project    = overrideProject();
    $module     = overrideModule($project);
    $frontDev   = overrideMember($project, 'developer', AccessScope::Frontend);
    $creator    = overrideMember($project, 'developer');

    $frontTask   = overrideTask($module, $creator, ['section' => 'frontend']);
    $backTask    = overrideTask($module, $creator, ['section' => 'backend']);
    $nullTask    = overrideTask($module, $creator, ['section' => null]);

    $visible = Task::visibleTo($frontDev)->pluck('id');

    expect($visible)->toContain($frontTask->id)
        ->toContain($nullTask->id)
        ->not->toContain($backTask->id);
});

it('member with access_scope=backend sees backend-sectioned and null-sectioned tasks', function () {
    $project  = overrideProject();
    $module   = overrideModule($project);
    $backDev  = overrideMember($project, 'developer', AccessScope::Backend);
    $creator  = overrideMember($project, 'developer');

    $frontTask = overrideTask($module, $creator, ['section' => 'frontend']);
    $backTask  = overrideTask($module, $creator, ['section' => 'backend']);
    $nullTask  = overrideTask($module, $creator, ['section' => null]);

    $visible = Task::visibleTo($backDev)->pluck('id');

    expect($visible)->toContain($backTask->id)
        ->toContain($nullTask->id)
        ->not->toContain($frontTask->id);
});

it('member with access_scope=all sees all sectioned and unsectioned tasks', function () {
    $project = overrideProject();
    $module  = overrideModule($project);
    $allDev  = overrideMember($project, 'developer', AccessScope::All);
    $creator = overrideMember($project, 'developer');

    $frontTask = overrideTask($module, $creator, ['section' => 'frontend']);
    $backTask  = overrideTask($module, $creator, ['section' => 'backend']);
    $nullTask  = overrideTask($module, $creator, ['section' => null]);

    $visible = Task::visibleTo($allDev)->pluck('id');

    expect($visible)->toContain($frontTask->id)
        ->toContain($backTask->id)
        ->toContain($nullTask->id);
});

it('super_admin sees tasks of all sections', function () {
    $project    = overrideProject();
    $module     = overrideModule($project);
    $creator    = overrideMember($project, 'developer');
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $frontTask = overrideTask($module, $creator, ['section' => 'frontend']);
    $backTask  = overrideTask($module, $creator, ['section' => 'backend']);
    $nullTask  = overrideTask($module, $creator, ['section' => null]);

    $visible = Task::visibleTo($superAdmin)->pluck('id');

    expect($visible)->toContain($frontTask->id)
        ->toContain($backTask->id)
        ->toContain($nullTask->id);
});

// ── multi-project: access scope is per project ────────────────────────────────

it('user with frontend scope on project A and backend scope on project B sees only frontend and unsectioned tasks in project A (scope is per project)', function () {
    $client = Client::create(['name' => 'Multi Corp', 'currency' => 'USD']);

    $projectA = Project::create(['client_id' => $client->id, 'name' => 'Project A', 'status' => 'active']);
    $projectB = Project::create(['client_id' => $client->id, 'name' => 'Project B', 'status' => 'active']);

    $moduleA = Module::create(['project_id' => $projectA->id, 'name' => 'Core A', 'position' => 1]);

    // One user — frontend in Project A, backend in Project B
    $user = User::factory()->create();
    $user->assignRole('developer');
    ProjectMember::create([
        'project_id'      => $projectA->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::Frontend,
        'active'          => true,
    ]);
    ProjectMember::create([
        'project_id'      => $projectB->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::Backend,
        'active'          => true,
    ]);

    // A creator to own tasks (also a member of Project A)
    $creator = User::factory()->create();
    $creator->assignRole('developer');
    ProjectMember::create([
        'project_id'      => $projectA->id,
        'user_id'         => $creator->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);

    $frontendTaskA = overrideTask($moduleA, $creator, ['section' => 'frontend']);
    $backendTaskA  = overrideTask($moduleA, $creator, ['section' => 'backend']);
    $nullTaskA     = overrideTask($moduleA, $creator, ['section' => null]);

    $visible = Task::visibleTo($user)->pluck('id');

    expect($visible)
        ->toContain($frontendTaskA->id)
        ->toContain($nullTaskA->id)
        ->not->toContain($backendTaskA->id); // backend scope on B must not leak into A
});

// ── section filter preserves draft visibility guarantee ───────────────────────

it('section filter does not expose other-user drafts', function () {
    $project   = overrideProject();
    $module    = overrideModule($project);
    $frontDev  = overrideMember($project, 'developer', AccessScope::Frontend);
    $otherDev  = overrideMember($project, 'developer');

    // draft owned by otherDev, frontend section — frontDev must NOT see it
    $draft = overrideTask($module, $otherDev, [
        'status'  => TaskStatus::Draft,
        'section' => 'frontend',
    ]);

    $visible = Task::visibleTo($frontDev)->pluck('id');

    expect($visible)->not->toContain($draft->id);
});

<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\Form;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers ──────────────────────────────────────────────────────────────────

function policyProject(): Project
{
    $client = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
}

function policyModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Core', 'position' => 1]);
}

function policyMember(Project $project, string $role = 'developer'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => $role,
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);
    return $user;
}

function policyTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'Policy Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::In,
        'position'       => 1,
    ], $attrs));
}

// ── view policy ───────────────────────────────────────────────────────────────

it('any project member can view a published task', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $creator = policyMember($project);
    $viewer  = policyMember($project);

    $task = policyTask($module, $creator, ['status' => TaskStatus::Published]);

    expect($viewer->can('view', $task))->toBeTrue();
});

it('creator can view their own draft task', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $creator = policyMember($project);

    $task = policyTask($module, $creator, ['status' => TaskStatus::Draft]);

    expect($creator->can('view', $task))->toBeTrue();
});

it('non-creator cannot view a draft task via policy', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $creator = policyMember($project);
    $other   = policyMember($project);

    $task = policyTask($module, $creator, ['status' => TaskStatus::Draft]);

    expect($other->can('view', $task))->toBeFalse();
});

it('super_admin can view a published task via Gate::before', function () {
    $project    = policyProject();
    $module     = policyModule($project);
    $creator    = policyMember($project);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $task = policyTask($module, $creator, ['status' => TaskStatus::Published]);

    expect($superAdmin->can('view', $task))->toBeTrue();
});

it('super_admin draft is hidden by visibleTo scope (policy is bypassed by Gate::before)', function () {
    $project    = policyProject();
    $module     = policyModule($project);
    $creator    = policyMember($project);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $task = policyTask($module, $creator, ['status' => TaskStatus::Draft]);

    // Gate::before returns true for super_admin but policy view() explicitly denies drafts
    // to non-creators. The TaskPolicy::view must check draft BEFORE super_admin short-circuits.
    // However since Gate::before short-circuits, we enforce draft hiding at the scope level.
    // The policy will return true for super_admin on any task — draft enforcement is in visibleTo().
    // This test verifies SCOPE-level hiding: super_admin must NOT see draft in query scope.
    // (Policy-level: super_admin CAN view via Gate::before — that's correct and expected.)
    expect(Task::visibleTo($superAdmin)->count())->toBe(0);
});

// ── update policy ─────────────────────────────────────────────────────────────

it('super_admin can update a task', function () {
    $project    = policyProject();
    $module     = policyModule($project);
    $creator    = policyMember($project);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $task = policyTask($module, $creator);

    expect($superAdmin->can('update', $task))->toBeTrue();
});

it('project_manager can update a task', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $pm      = policyMember($project, 'project_manager');

    $task = policyTask($module, $pm);

    expect($pm->can('update', $task))->toBeTrue();
});

it('an assignee can update a task', function () {
    $project  = policyProject();
    $module   = policyModule($project);
    $creator  = policyMember($project);
    $assignee = policyMember($project, 'designer');

    $task = policyTask($module, $creator);
    $task->assignees()->attach($assignee->id);

    expect($assignee->can('update', $task))->toBeTrue();
});

it('a non-assignee developer cannot update a task', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $creator = policyMember($project);
    $dev     = policyMember($project);

    $task = policyTask($module, $creator);
    // dev not added as assignee

    expect($dev->can('update', $task))->toBeFalse();
});

// ── updateCostTime policy (developer restriction) ─────────────────────────────

it('developer assignee is denied updateCostTime', function () {
    $project  = policyProject();
    $module   = policyModule($project);
    $creator  = policyMember($project);
    $dev      = policyMember($project, 'developer');

    $task = policyTask($module, $creator);
    $task->assignees()->attach($dev->id);

    expect($dev->can('updateCostTime', $task))->toBeFalse();
});

it('project_manager can updateCostTime', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $pm      = policyMember($project, 'project_manager');

    $task = policyTask($module, $pm);

    expect($pm->can('updateCostTime', $task))->toBeTrue();
});

it('super_admin can updateCostTime', function () {
    $project    = policyProject();
    $module     = policyModule($project);
    $creator    = policyMember($project);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $task = policyTask($module, $creator);

    expect($superAdmin->can('updateCostTime', $task))->toBeTrue();
});

it('designer assignee can updateCostTime', function () {
    $project  = policyProject();
    $module   = policyModule($project);
    $creator  = policyMember($project);
    $designer = policyMember($project, 'designer');

    $task = policyTask($module, $creator);
    $task->assignees()->attach($designer->id);

    expect($designer->can('updateCostTime', $task))->toBeTrue();
});

// ── comment policy ────────────────────────────────────────────────────────────

it('developer assignee can comment', function () {
    $project  = policyProject();
    $module   = policyModule($project);
    $creator  = policyMember($project);
    $dev      = policyMember($project, 'developer');

    $task = policyTask($module, $creator);
    $task->assignees()->attach($dev->id);

    expect($dev->can('comment', $task))->toBeTrue();
});

it('non-assignee cannot comment', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $creator = policyMember($project);
    $dev     = policyMember($project, 'developer');

    $task = policyTask($module, $creator);
    // dev not added as assignee

    expect($dev->can('comment', $task))->toBeFalse();
});

it('super_admin can comment', function () {
    $project    = policyProject();
    $module     = policyModule($project);
    $creator    = policyMember($project);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $task = policyTask($module, $creator);

    expect($superAdmin->can('comment', $task))->toBeTrue();
});

// ── Form: developer save does NOT change cost/time fields ─────────────────────

it('developer assignee save does NOT change estimate_hours or budget amount', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $creator = policyMember($project, 'project_manager');
    $dev     = policyMember($project, 'developer');

    $task = policyTask($module, $creator, [
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::Out,
        'budget_amount'          => 100.0,
    ]);
    $task->assignees()->attach($dev->id);

    Livewire::actingAs($dev)
        ->test(Form::class, ['task' => $task])
        ->set('estimate_hours', 99.0)
        ->set('budget_amount', 999.0)
        ->call('save');

    $task->refresh();
    expect((float) $task->estimate_hours)->toBe(8.0);
    expect((float) $task->budget_amount)->toBe(100.0);
});

it('project_manager save changes estimate_hours but not the budget amount (money is owner only)', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $pm      = policyMember($project, 'project_manager');

    $task = policyTask($module, $pm, [
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::Out,
        'budget_amount'          => 100.0,
    ]);

    Livewire::actingAs($pm)
        ->test(Form::class, ['task' => $task])
        ->set('estimate_hours', 20.0)
        ->set('budget_amount', 500.0)
        ->call('save');

    $task->refresh();
    expect((float) $task->estimate_hours)->toBe(20.0);
    expect((float) $task->budget_amount)->toBe(100.0);
});

// ── Finding 2: developer CREATE does NOT persist estimate_hours / budget amount ────────

it('developer creating a task has estimate_hours and budget amount stripped', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $dev     = policyMember($project, 'developer');

    Livewire::actingAs($dev)
        ->test(Form::class)
        ->set('module_id', $module->id)
        ->set('title', 'Dev Task')
        ->set('status', 'published')
        ->set('scope', 'in')
        ->set('estimate_hours', 99.0)
        ->set('budget_amount', 999.0)
        ->call('save')
        ->assertHasNoErrors();

    $task = \App\Models\Task::where('title', 'Dev Task')->firstOrFail();
    // estimate_hours defaults to 0.0 (model default), budget amount stays null
    expect((float) $task->estimate_hours)->toBe(0.0);
    expect($task->budget_amount)->toBeNull();
});

it('project_manager creating a task CAN set estimate_hours but not a budget amount', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $pm      = policyMember($project, 'project_manager');

    Livewire::actingAs($pm)
        ->test(Form::class)
        ->set('module_id', $module->id)
        ->set('title', 'PM Task')
        ->set('status', 'published')
        ->set('scope', 'in')
        ->set('estimate_hours', 8.0)
        ->set('budget_amount', 200.0)
        ->call('save')
        ->assertHasNoErrors();

    $task = \App\Models\Task::where('title', 'PM Task')->firstOrFail();
    expect((float) $task->estimate_hours)->toBe(8.0);
    expect($task->budget_amount)->toBeNull();
});

// ── Finding 3: unknown board column is rejected ────────────────────────────────

it('unknown board column is rejected with a validation error', function () {
    $project = policyProject();
    $module  = policyModule($project);
    $pm      = policyMember($project, 'project_manager');

    Livewire::actingAs($pm)
        ->test(Form::class)
        ->set('module_id', $module->id)
        ->set('title', 'Bad Column Task')
        ->set('status', 'published')
        ->set('scope', 'in')
        ->set('board_column_id', 999999)
        ->call('save')
        ->assertHasErrors(['board_column_id']);
});

<?php

use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Enums\AccessScope;
use Database\Seeders\RoleSeeder;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers ──────────────────────────────────────────────────────────────────

function makeTaskProject(): Project
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'Alpha', 'status' => 'active']);
}

function makeModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Core', 'position' => 1]);
}

function memberOfProject(Project $project, string $role = 'developer'): User
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

function makeTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'   => $module->id,
        'creator_id'  => $creator->id,
        'title'       => 'Test Task',
        'status'      => TaskStatus::Published,
        'priority'    => 1,
        'estimate_hours' => 8.0,
        'scope'       => TaskScope::In,
        'board_column' => 'Backlog',
        'position'    => 1,
    ], $attrs));
}

// ── effectiveHours ────────────────────────────────────────────────────────────

it('effectiveHours returns estimate when testing disabled', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator, [
        'estimate_hours'    => 10.0,
        'testing_enabled'   => false,
    ]);

    expect($task->effectiveHours())->toBe(10.0);
});

it('effectiveHours adds percent testing add-on', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator, [
        'estimate_hours'   => 10.0,
        'testing_enabled'  => true,
        'testing_add_type' => 'percent',
        'testing_add_value' => 20.0,
    ]);

    // 10 * (1 + 20/100) = 12.0
    expect($task->effectiveHours())->toBe(12.0);
});

it('effectiveHours adds fixed testing add-on', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator, [
        'estimate_hours'    => 10.0,
        'testing_enabled'   => true,
        'testing_add_type'  => 'fixed',
        'testing_add_value' => 3.0,
    ]);

    // 10 + 3 = 13.0
    expect($task->effectiveHours())->toBe(13.0);
});

// ── idea flag ─────────────────────────────────────────────────────────────────

it('is_idea flag defaults to false', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator);

    expect($task->is_idea)->toBeFalse();
});

it('can mark a task as an idea', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator, ['is_idea' => true]);

    expect($task->is_idea)->toBeTrue();
});

// ── price / scope ─────────────────────────────────────────────────────────────

it('price is null for in-scope tasks', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator, ['scope' => TaskScope::In, 'price' => null]);

    expect($task->price)->toBeNull();
    expect($task->scope)->toBe(TaskScope::In);
});

it('price is meaningful for out-of-scope tasks', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator, ['scope' => TaskScope::Out, 'price' => 500.0]);

    expect($task->scope)->toBe(TaskScope::Out);
    expect($task->price)->toBe(500.0);
});

// ── relations ─────────────────────────────────────────────────────────────────

it('task belongs to a module', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator);

    expect($task->module->id)->toBe($module->id);
});

it('task belongs to a creator', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $task = makeTask($module, $creator);

    expect($task->creator->id)->toBe($creator->id);
});

it('module has tasks relationship', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    makeTask($module, $creator);
    makeTask($module, $creator, ['title' => 'Task 2']);

    expect($module->tasks()->count())->toBe(2);
});

it('task can have assignees', function () {
    $project  = makeTaskProject();
    $module   = makeModule($project);
    $creator  = memberOfProject($project);
    $assignee = memberOfProject($project);

    $task = makeTask($module, $creator);
    $task->assignees()->attach($assignee->id);

    expect($task->assignees()->count())->toBe(1);
    expect($task->assignees()->first()->id)->toBe($assignee->id);
});

it('task can have labels', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);

    $label = Label::create(['name' => 'Bug', 'color' => '#ff0000']);
    $task  = makeTask($module, $creator);
    $task->labels()->attach($label->id);

    expect($task->labels()->count())->toBe(1);
    expect($task->labels()->first()->name)->toBe('Bug');
});

// ── visibleTo scope (draft hiding) ────────────────────────────────────────────

it('published tasks are visible to all members via visibleTo', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);
    $other   = memberOfProject($project);

    makeTask($module, $creator, ['status' => TaskStatus::Published]);

    expect(Task::visibleTo($other)->count())->toBe(1);
});

it('draft task is visible only to its creator via visibleTo', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);
    $other   = memberOfProject($project);

    makeTask($module, $creator, ['status' => TaskStatus::Draft]);

    expect(Task::visibleTo($creator)->count())->toBe(1);
    expect(Task::visibleTo($other)->count())->toBe(0);
});

it('draft task is NOT visible to super_admin via visibleTo', function () {
    $project   = makeTaskProject();
    $module    = makeModule($project);
    $creator   = memberOfProject($project);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    makeTask($module, $creator, ['status' => TaskStatus::Draft]);

    expect(Task::visibleTo($superAdmin)->count())->toBe(0);
});

it('draft task is NOT visible to project_manager via visibleTo', function () {
    $project = makeTaskProject();
    $module  = makeModule($project);
    $creator = memberOfProject($project);
    $pm      = memberOfProject($project, 'project_manager');

    makeTask($module, $creator, ['status' => TaskStatus::Draft]);

    expect(Task::visibleTo($pm)->count())->toBe(0);
});

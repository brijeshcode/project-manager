<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\Board;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
});

// ── helpers ───────────────────────────────────────────────────────────────────

function boardProject(): Project
{
    $client = Client::create(['name' => 'BoardCo', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'BoardProject', 'status' => 'active']);
}

function boardModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'BMod', 'position' => 1]);
}

function boardMember(Project $project, string $role = 'developer'): User
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

function boardTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'Board Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 0.0,
        'scope'          => TaskScope::In,
        'position'       => 1,
    ], $attrs));
}

// ── moveTo() ──────────────────────────────────────────────────────────────────

it('moveTo persists new column and position', function () {
    $project = boardProject();
    $module  = boardModule($project);
    $user    = boardMember($project);
    $task    = boardTask($module, $user);
    $other   = boardTask($module, $user, ['position' => 2, 'board_column_id' => colId($module, 'In progress')]);

    $task->moveTo(colId($module, 'In progress'), 2);

    // slot 2 in the column: after the task already there; positions are the module's single order
    $fresh = $task->fresh();
    expect($fresh->board_column_id)->toBe(colId($module, 'In progress'))
        ->and($other->fresh()->position)->toBe(1)
        ->and($fresh->position)->toBe(2);
});

it('moveTo ignores invalid column', function () {
    $project = boardProject();
    $module  = boardModule($project);
    $user    = boardMember($project);
    $task    = boardTask($module, $user, ['position' => 1]);

    $other = boardProject();
    $task->moveTo(colId($other, 'Done'), 99);   // another project's column

    $fresh = $task->fresh();
    expect($fresh->board_column_id)->toBe(colId($module, 'Backlog'))
        ->and($fresh->position)->toBe(1);
});

// ── Board Livewire component ──────────────────────────────────────────────────

it('board renders the template column names', function () {
    $project = boardProject();
    $user    = boardMember($project);

    Livewire::actingAs($user)
        ->test(Board::class, ['project' => $project])
        ->assertSee('Backlog')
        ->assertSee('In progress')
        ->assertSee('Testing')
        ->assertSee('Done');
});

it('board shows tasks in their column', function () {
    $project = boardProject();
    $module  = boardModule($project);
    $user    = boardMember($project);

    boardTask($module, $user, ['title' => 'Backlog Task']);
    boardTask($module, $user, ['title' => 'InProg Task', 'board_column_id' => colId($module, 'In progress')]);

    $component = Livewire::actingAs($user)
        ->test(Board::class, ['project' => $project]);

    $columns = $component->get('columns');

    $b = colId($module, 'Backlog');
    $p = colId($module, 'In progress');

    expect($columns[$b])->toHaveCount(1)
        ->and($columns[$b]->first()->title)->toBe('Backlog Task')
        ->and($columns[$p])->toHaveCount(1)
        ->and($columns[$p]->first()->title)->toBe('InProg Task');
});

it('board excludes drafts from other users', function () {
    $project     = boardProject();
    $module      = boardModule($project);
    $draftOwner  = boardMember($project);
    $viewer      = boardMember($project);

    boardTask($module, $draftOwner, ['title' => 'Secret Draft', 'status' => TaskStatus::Draft]);

    $component = Livewire::actingAs($viewer)
        ->test(Board::class, ['project' => $project]);

    $columns = $component->get('columns');

    expect($columns->flatten())->toHaveCount(0);
});

it('board rejects a non-member', function () {
    $project  = boardProject();
    $outsider = User::factory()->create();
    $outsider->assignRole('developer');

    Livewire::actingAs($outsider)
        ->test(Board::class, ['project' => $project])
        ->assertForbidden();
});

it('taskMoved event moves the task and re-renders', function () {
    $project = boardProject();
    $module  = boardModule($project);
    $user    = boardMember($project, 'project_manager');

    $task = boardTask($module, $user, ['position' => 1]);

    Livewire::actingAs($user)
        ->test(Board::class, ['project' => $project])
        ->call('taskMoved', $task->id, colId($module, 'Done'), 1);

    expect($task->fresh()->board_column_id)->toBe(colId($module, 'Done'));
});

it('taskMoved is denied for a user without project access', function () {
    // Project A: task lives here
    $projectA = boardProject();
    $moduleA  = boardModule($projectA);
    $ownerA   = boardMember($projectA, 'project_manager');
    $task     = boardTask($moduleA, $ownerA, ['position' => 1]);

    // Project B: memberB is a valid member here, so mount succeeds
    $projectB = boardProject();
    $memberB  = boardMember($projectB, 'developer');

    // Mount Board for Project B (passes), then attempt to move a task from Project A
    Livewire::actingAs($memberB)
        ->test(Board::class, ['project' => $projectB])
        ->call('taskMoved', $task->id, colId($projectB, 'Done'), 1);

    // taskMoved scopes via whereIn(project B's module IDs) → findOrFail rejects the foreign task
    // column must remain unchanged
    expect($task->fresh()->board_column_id)->toBe(colId($moduleA, 'Backlog'));
});

it('board page is reachable via route for project members', function () {
    $project = boardProject();
    $user    = boardMember($project);

    $this->actingAs($user)
        ->get(route('projects.board', $project))
        ->assertOk();
});

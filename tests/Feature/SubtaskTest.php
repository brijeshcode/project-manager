<?php

use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use Database\Seeders\RoleSeeder;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers ──────────────────────────────────────────────────────────────────

function makeSubtaskProject(): Project
{
    $client = Client::create(['name' => 'SubCo', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'SubProj', 'status' => 'active']);
}

function makeSubtaskModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Mod', 'position' => 1]);
}

function memberOfSubtaskProject(Project $project, string $role = 'developer'): User
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

function makeSubtaskTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'Parent Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::In,
        'position'       => 1,
    ], $attrs));
}

// ── subtask creation & position ───────────────────────────────────────────────

it('can create a subtask under a task', function () {
    $project = makeSubtaskProject();
    $module  = makeSubtaskModule($project);
    $creator = memberOfSubtaskProject($project);
    $task    = makeSubtaskTask($module, $creator);

    $subtask = Subtask::create([
        'task_id'        => $task->id,
        'title'          => 'Write unit tests',
        'estimate_hours' => 2.0,
        'position'       => 1,
    ]);

    expect($subtask->task->id)->toBe($task->id);
    expect($subtask->estimate_hours)->toBe(2.0);
});

it('subtasks are ordered by position', function () {
    $project = makeSubtaskProject();
    $module  = makeSubtaskModule($project);
    $creator = memberOfSubtaskProject($project);
    $task    = makeSubtaskTask($module, $creator);

    Subtask::create(['task_id' => $task->id, 'title' => 'Third', 'estimate_hours' => 1.0, 'position' => 3]);
    Subtask::create(['task_id' => $task->id, 'title' => 'First',  'estimate_hours' => 1.0, 'position' => 1]);
    Subtask::create(['task_id' => $task->id, 'title' => 'Second', 'estimate_hours' => 1.0, 'position' => 2]);

    $titles = $task->subtasks()->pluck('title')->all();
    expect($titles)->toBe(['First', 'Second', 'Third']);
});

it('nextPosition returns 1 for first subtask', function () {
    $project = makeSubtaskProject();
    $module  = makeSubtaskModule($project);
    $creator = memberOfSubtaskProject($project);
    $task    = makeSubtaskTask($module, $creator);

    expect(Subtask::nextPosition($task->id))->toBe(1);
});

it('nextPosition increments for subsequent subtasks', function () {
    $project = makeSubtaskProject();
    $module  = makeSubtaskModule($project);
    $creator = memberOfSubtaskProject($project);
    $task    = makeSubtaskTask($module, $creator);

    Subtask::create(['task_id' => $task->id, 'title' => 'S1', 'estimate_hours' => 1.0, 'position' => 1]);
    Subtask::create(['task_id' => $task->id, 'title' => 'S2', 'estimate_hours' => 1.0, 'position' => 2]);

    expect(Subtask::nextPosition($task->id))->toBe(3);
});

// ── assignees ─────────────────────────────────────────────────────────────────

it('subtask can have one assignee', function () {
    $project  = makeSubtaskProject();
    $module   = makeSubtaskModule($project);
    $creator  = memberOfSubtaskProject($project);
    $assignee = memberOfSubtaskProject($project);
    $task     = makeSubtaskTask($module, $creator);

    $subtask = Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 1.0, 'position' => 1]);
    $subtask->assignees()->attach($assignee->id);

    expect($subtask->assignees()->count())->toBe(1);
    expect($subtask->assignees()->first()->id)->toBe($assignee->id);
});

it('subtask can have multiple assignees', function () {
    $project   = makeSubtaskProject();
    $module    = makeSubtaskModule($project);
    $creator   = memberOfSubtaskProject($project);
    $assignee1 = memberOfSubtaskProject($project);
    $assignee2 = memberOfSubtaskProject($project);
    $task      = makeSubtaskTask($module, $creator);

    $subtask = Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 3.0, 'position' => 1]);
    $subtask->assignees()->attach([$assignee1->id, $assignee2->id]);

    expect($subtask->assignees()->count())->toBe(2);
});

// ── totalEstimateHours ────────────────────────────────────────────────────────

it('totalEstimateHours equals effectiveHours when no subtasks', function () {
    $project = makeSubtaskProject();
    $module  = makeSubtaskModule($project);
    $creator = memberOfSubtaskProject($project);
    $task    = makeSubtaskTask($module, $creator, ['estimate_hours' => 5.0]);

    expect($task->totalEstimateHours())->toBe($task->effectiveHours());
    expect($task->totalEstimateHours())->toBe(5.0);
});

it('totalEstimateHours sums effectiveHours and subtask hours', function () {
    $project = makeSubtaskProject();
    $module  = makeSubtaskModule($project);
    $creator = memberOfSubtaskProject($project);
    $task    = makeSubtaskTask($module, $creator, ['estimate_hours' => 8.0]);

    Subtask::create(['task_id' => $task->id, 'title' => 'S1', 'estimate_hours' => 2.0, 'position' => 1]);
    Subtask::create(['task_id' => $task->id, 'title' => 'S2', 'estimate_hours' => 3.0, 'position' => 2]);

    // 8.0 + 2.0 + 3.0 = 13.0
    expect($task->totalEstimateHours())->toBe(13.0);
});

it('totalEstimateHours uses effectiveHours (not raw estimate) when testing add-on is on', function () {
    $project = makeSubtaskProject();
    $module  = makeSubtaskModule($project);
    $creator = memberOfSubtaskProject($project);
    $task    = makeSubtaskTask($module, $creator, [
        'estimate_hours'    => 10.0,
        'testing_enabled'   => true,
        'testing_add_type'  => 'percent',
        'testing_add_value' => 20.0,  // effectiveHours = 12.0
    ]);

    Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 4.0, 'position' => 1]);

    // 12.0 (effective) + 4.0 (subtask) = 16.0, NOT 10.0 + 4.0
    expect($task->totalEstimateHours())->toBe(16.0);
});

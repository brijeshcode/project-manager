<?php

use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\ProgressService;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Enums\AccessScope;
use App\Models\ProjectMember;
use Database\Seeders\RoleSeeder;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers ───────────────────────────────────────────────────────────────────

function psProject(): Project
{
    $client = Client::create(['name' => 'PSCo', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'PSProj', 'status' => 'active']);
}

function psModule(Project $project, string $name = 'Mod'): Module
{
    return Module::create(['project_id' => $project->id, 'name' => $name, 'position' => 1]);
}

function psUser(Project $project): User
{
    $user = User::factory()->create();
    $user->assignRole('developer');
    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);
    return $user;
}

function psTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'PS Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::In,
        'position'       => 1,
    ], $attrs));
}

function psDone(Project $project): int
{
    return $project->columns()->where('name', 'Done')->value('id');
}

function addItem(Task|Subtask $owner, string $title, bool $done = false): ChecklistItem
{
    return ChecklistItem::create([
        'checklistable_type' => get_class($owner),
        'checklistable_id'   => $owner->id,
        'title'              => $title,
        'is_done'            => $done,
        'position'           => ChecklistItem::nextPosition(get_class($owner), $owner->id),
    ]);
}

// ── forTask ───────────────────────────────────────────────────────────────────

it('forTask: task with no items returns 0.0, no error', function () {
    $project = psProject();
    $module  = psModule($project);
    $user    = psUser($project);
    $task    = psTask($module, $user);

    $service = new ProgressService();
    expect($service->forTask($task))->toBe(0.0);
});

it('forTask: 2 of 4 items done = 2/5 = 40% (the Done step counts)', function () {
    $project = psProject();
    $module  = psModule($project);
    $user    = psUser($project);
    $task    = psTask($module, $user);

    addItem($task, 'A', true);
    addItem($task, 'B', true);
    addItem($task, 'C', false);
    addItem($task, 'D', false);

    $service = new ProgressService();
    expect($service->forTask($task))->toBe(40.0);
});

it('forTask: all items ticked outside Done = 2/3, in Done = 100%', function () {
    $project = psProject();
    $module  = psModule($project);
    $user    = psUser($project);
    $task    = psTask($module, $user);

    addItem($task, 'A', true);
    addItem($task, 'B', true);

    $service = new ProgressService();
    expect(round($service->forTask($task), 2))->toBe(66.67);

    $task->update(['board_column_id' => psDone($project)]);
    expect($service->forTask($task->fresh()))->toBe(100.0);
});

it('forTask: a subtask is one step (its is_done); its own checklist items are not pooled in', function () {
    // 1 of 1 item ticked + 1 undone subtask + the Done step = 1/3
    $project = psProject();
    $module  = psModule($project);
    $user    = psUser($project);
    $task    = psTask($module, $user);

    addItem($task, 'Task-done', true);

    $subtask = Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 1.0, 'position' => 1]);
    addItem($subtask, 'Sub-done', true);

    $service = new ProgressService();
    expect(round($service->forTask($task), 2))->toBe(33.33);

    $subtask->update(['is_done' => true]);
    expect($service->forTask($task->fresh()))->toBe(round(2 / 3 * 100, 10));
});

// ── forModule ─────────────────────────────────────────────────────────────────

it('forModule: hours-weighted — 40h at 100% + 10h at 0% ≈ 80%', function () {
    $project = psProject();
    $module  = psModule($project);
    $user    = psUser($project);

    $task40 = psTask($module, $user, ['estimate_hours' => 40.0, 'title' => 'Big', 'board_column_id' => psDone($project)]);   // 100%

    $task10 = psTask($module, $user, ['estimate_hours' => 10.0, 'title' => 'Small', 'position' => 2]);
    addItem($task10, 'B', false);  // 0%

    $service = new ProgressService();
    // (40 * 100 + 10 * 0) / 50 = 80
    expect($service->forModule($module))->toBe(80.0);
});

it('forModule: 0-hour task does not crash weighting', function () {
    $project = psProject();
    $module  = psModule($project);
    $user    = psUser($project);

    $task0 = psTask($module, $user, ['estimate_hours' => 0.0, 'title' => 'Zero']);
    addItem($task0, 'X', true);

    $service = new ProgressService();
    // no task has hours: plain average of the task progress (1 of 1 ticked + Done step = 50%)
    expect($service->forModule($module))->toBe(50.0);
});

it('forModule: module with no tasks returns 0.0', function () {
    $project = psProject();
    $module  = psModule($project);

    $service = new ProgressService();
    expect($service->forModule($module))->toBe(0.0);
});

// ── forProject ────────────────────────────────────────────────────────────────

it('forProject: rolls modules up hours-weighted to project', function () {
    $project = psProject();
    $user    = psUser($project);

    // Module 1: task with 40h, 1 of 2 items done (+ Done step) = 33.33%
    $mod1   = Module::create(['project_id' => $project->id, 'name' => 'M1', 'position' => 1]);
    $task1a = psTask($mod1, $user, ['estimate_hours' => 40.0, 'title' => 'T1a', 'position' => 1]);
    addItem($task1a, 'I1', true);
    addItem($task1a, 'I2', false);
    // mod1 progress = 33.33%, weight = 40h

    // Module 2: task with 10h, all done (100%)
    $mod2   = Module::create(['project_id' => $project->id, 'name' => 'M2', 'position' => 2]);
    $task2a = psTask($mod2, $user, ['estimate_hours' => 10.0, 'title' => 'T2a', 'position' => 1, 'board_column_id' => psDone($project)]);
    // mod2 progress = 100%, weight = 10h

    // Project progress: (40 * 33.33 + 10 * 100) / 50 = 46.67
    $service = new ProgressService();
    expect(round($service->forProject($project), 2))->toBe(46.67);
});

it('forProject: project with no modules returns 0.0', function () {
    $project = psProject();

    $service = new ProgressService();
    expect($service->forProject($project))->toBe(0.0);
});

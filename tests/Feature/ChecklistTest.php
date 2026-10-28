<?php

use App\Models\ChecklistItem;
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

// ── helpers ───────────────────────────────────────────────────────────────────

function clProject(): Project
{
    $client = Client::create(['name' => 'CLCo', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'CLProj', 'status' => 'active']);
}

function clModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Mod', 'position' => 1]);
}

function clUser(Project $project): User
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

function clTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'CL Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::In,
        'board_column'   => 'Backlog',
        'position'       => 1,
    ], $attrs));
}

// ── ChecklistItem model ───────────────────────────────────────────────────────

it('can attach checklist items to a task', function () {
    $project = clProject();
    $module  = clModule($project);
    $user    = clUser($project);
    $task    = clTask($module, $user);

    ChecklistItem::create([
        'checklistable_type' => Task::class,
        'checklistable_id'   => $task->id,
        'title'              => 'Write test',
        'is_done'            => false,
        'position'           => 1,
    ]);

    expect($task->checklistItems()->count())->toBe(1);
    expect($task->checklistItems()->first()->title)->toBe('Write test');
});

it('can attach checklist items to a subtask', function () {
    $project = clProject();
    $module  = clModule($project);
    $user    = clUser($project);
    $task    = clTask($module, $user);

    $subtask = Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 1.0, 'position' => 1]);

    ChecklistItem::create([
        'checklistable_type' => Subtask::class,
        'checklistable_id'   => $subtask->id,
        'title'              => 'Sub item',
        'is_done'            => true,
        'position'           => 1,
    ]);

    expect($subtask->checklistItems()->count())->toBe(1);
    expect($subtask->checklistItems()->first()->is_done)->toBeTrue();
});

it('checklist item is_done can be toggled', function () {
    $project = clProject();
    $module  = clModule($project);
    $user    = clUser($project);
    $task    = clTask($module, $user);

    $item = ChecklistItem::create([
        'checklistable_type' => Task::class,
        'checklistable_id'   => $task->id,
        'title'              => 'Toggle me',
        'is_done'            => false,
        'position'           => 1,
    ]);

    $item->update(['is_done' => true]);
    expect($item->fresh()->is_done)->toBeTrue();
});

// ── position / reorder ────────────────────────────────────────────────────────

it('nextPosition returns 1 for the first item on a task', function () {
    $project = clProject();
    $module  = clModule($project);
    $user    = clUser($project);
    $task    = clTask($module, $user);

    expect(ChecklistItem::nextPosition(Task::class, $task->id))->toBe(1);
});

it('items reorder and persist', function () {
    $project = clProject();
    $module  = clModule($project);
    $user    = clUser($project);
    $task    = clTask($module, $user);

    $a = ChecklistItem::create(['checklistable_type' => Task::class, 'checklistable_id' => $task->id, 'title' => 'A', 'is_done' => false, 'position' => 1]);
    $b = ChecklistItem::create(['checklistable_type' => Task::class, 'checklistable_id' => $task->id, 'title' => 'B', 'is_done' => false, 'position' => 2]);
    $c = ChecklistItem::create(['checklistable_type' => Task::class, 'checklistable_id' => $task->id, 'title' => 'C', 'is_done' => false, 'position' => 3]);

    // Reorder: C, A, B
    ChecklistItem::reorder([$c->id, $a->id, $b->id], Task::class, $task->id);

    $ordered = $task->checklistItems()->orderBy('position')->pluck('title')->all();
    expect($ordered)->toBe(['C', 'A', 'B']);
});

it('reorder is scoped — does not affect another task\'s items', function () {
    $project = clProject();
    $module  = clModule($project);
    $user    = clUser($project);
    $task1   = clTask($module, $user, ['title' => 'T1', 'position' => 1]);
    $task2   = clTask($module, $user, ['title' => 'T2', 'position' => 2]);

    $t1a = ChecklistItem::create(['checklistable_type' => Task::class, 'checklistable_id' => $task1->id, 'title' => 'T1A', 'is_done' => false, 'position' => 1]);
    $t2a = ChecklistItem::create(['checklistable_type' => Task::class, 'checklistable_id' => $task2->id, 'title' => 'T2A', 'is_done' => false, 'position' => 1]);

    // Reorder task1 — should not touch task2
    ChecklistItem::reorder([$t1a->id], Task::class, $task1->id);

    expect($t2a->fresh()->position)->toBe(1);
});

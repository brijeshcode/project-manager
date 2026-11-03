<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Projects\Timeline;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers ───────────────────────────────────────────────────────────────────

function estProject(array $attrs = []): Project
{
    $client = Client::create(['name' => 'EstCo', 'currency' => 'USD']);
    return Project::create(array_merge([
        'client_id'      => $client->id,
        'name'           => 'EstProj',
        'status'         => 'active',
        'buffer_percent' => 0.0,
        'start_date'     => '2026-10-26', // Monday
    ], $attrs));
}

function estModule(Project $project, string $name = 'Mod'): Module
{
    return Module::create(['project_id' => $project->id, 'name' => $name, 'position' => 1]);
}

function estUser(Project $project): User
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

function estTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'Est Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 8.0,
        'scope'          => TaskScope::In,
        'position'       => 1,
    ], $attrs));
}

// ── Module::timelineHours ─────────────────────────────────────────────────────

it('Module::timelineHours sums task totalEstimateHours', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    estTask($module, $user, ['estimate_hours' => 10.0, 'position' => 1]);
    estTask($module, $user, ['estimate_hours' => 6.0,  'position' => 2]);

    $module->load('tasks.subtasks');
    expect($module->timelineHours())->toBe(16.0);
});

it('Module::timelineHours includes subtask hours via totalEstimateHours', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    $task = estTask($module, $user, ['estimate_hours' => 8.0]);
    Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 4.0, 'position' => 1]);

    $module->load('tasks.subtasks');
    // task effectiveHours(8) + subtask(4) = 12
    expect($module->timelineHours())->toBe(12.0);
});

// ── Project::deadline ─────────────────────────────────────────────────────────

it('Project::deadline returns correct working-day deadline', function () {
    // 1 task × 8h, no buffer → 1 working day from Monday 2026-10-26 = Monday 2026-10-26
    $project = estProject(['buffer_percent' => 0.0, 'start_date' => '2026-10-26']);
    $module  = estModule($project);
    $user    = estUser($project);

    estTask($module, $user, ['estimate_hours' => 8.0]);

    expect($project->estimatedCompletion()->toDateString())->toBe('2026-10-26');
});

it('Project::deadline applies buffer after conversion', function () {
    // 40h (5 working days) + 20% buffer → 48h = 6 working days
    // Start Monday 2026-10-26 → Mon/Tue/Wed/Thu/Fri/Mon → 2026-11-02
    $project = estProject(['buffer_percent' => 20.0, 'start_date' => '2026-10-26']);
    $module  = estModule($project);
    $user    = estUser($project);

    estTask($module, $user, ['estimate_hours' => 40.0]);

    expect($project->estimatedCompletion()->toDateString())->toBe('2026-11-02');
});

// ── Project::timeBar ──────────────────────────────────────────────────────────

it('Project::timeBar returns 0.0 when no estimated hours (divide-by-zero guard)', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    estTask($module, $user, ['estimate_hours' => 0.0]);

    expect($project->timeBar())->toBe(0.0);
});

it('Project::timeBar = logged ÷ estimated hours', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    $task = estTask($module, $user, ['estimate_hours' => 8.0]);
    TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 4.0,
        'worked_on'     => '2026-10-26',
    ]);

    expect($project->timeBar())->toBe(0.5); // 4 ÷ 8
});

it('Project::timeBar includes subtask logged hours', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    $task    = estTask($module, $user, ['estimate_hours' => 8.0]);
    $subtask = Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 4.0, 'position' => 1]);

    TimeEntry::create(['timeable_type' => Task::class,    'timeable_id' => $task->id,    'user_id' => $user->id, 'hours' => 4.0, 'worked_on' => '2026-10-26']);
    TimeEntry::create(['timeable_type' => Subtask::class, 'timeable_id' => $subtask->id, 'user_id' => $user->id, 'hours' => 2.0, 'worked_on' => '2026-10-26']);

    // estimated = task(8) + subtask(4) = 12; logged = 4 + 2 = 6; bar = 6/12 = 0.5
    expect($project->timeBar())->toBe(0.5);
});

// ── Project::estimateVsActual ─────────────────────────────────────────────────

it('Project::estimateVsActual reports both numbers', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    $task = estTask($module, $user, ['estimate_hours' => 16.0]);
    TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 10.0,
        'worked_on'     => '2026-10-26',
    ]);

    $result = $project->estimateVsActual();

    expect($result['estimated'])->toBe(16.0);
    expect($result['logged'])->toBe(10.0);
});

// ── Timeline Livewire component ───────────────────────────────────────────────

it('Timeline component renders both progress bars', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    $task = estTask($module, $user, ['estimate_hours' => 8.0]);
    TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 4.0,
        'worked_on'     => '2026-10-26',
    ]);

    Livewire::actingAs($user)
        ->test(Timeline::class, ['project' => $project])
        ->assertSee('Checklist progress')
        ->assertSee('Time logged');
});

it('Timeline component shows correct logged vs estimated hours', function () {
    $project = estProject();
    $module  = estModule($project);
    $user    = estUser($project);

    $task = estTask($module, $user, ['estimate_hours' => 8.0]);
    TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 4.0,
        'worked_on'     => '2026-10-26',
    ]);

    Livewire::actingAs($user)
        ->test(Timeline::class, ['project' => $project])
        ->assertSee('4.0h')
        ->assertSee('8.0h');
});

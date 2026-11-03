<?php

use App\Enums\AccessScope;
use App\Enums\CostRateType;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Time\Logger;
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

// ── helpers ──────────────────────────────────────────────────────────────────

function timeProject(): Project
{
    $client = Client::create(['name' => 'TimeCo', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'TimeProj', 'status' => 'active']);
}

function timeModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Mod', 'position' => 1]);
}

function timeMember(Project $project, string $role = 'developer', array $userAttrs = []): User
{
    $user = User::factory()->create($userAttrs);
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

function timeTask(Module $module, User $creator): Task
{
    return Task::create([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'Time Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 4.0,
        'scope'          => TaskScope::In,
        'position'       => 1,
    ]);
}

// ── hourly cost ───────────────────────────────────────────────────────────────

it('hourly entry derivedCost = hours × rate', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project, 'developer', [
        'cost_rate_type'   => CostRateType::Hourly,
        'cost_rate_amount' => 50.0,
    ]);
    $task = timeTask($module, $user);

    $entry = TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 3.0,
        'worked_on'     => '2026-10-01',
    ]);

    expect($entry->derivedCost())->toBe(150.0); // 3.0 × 50.0
});

it('User::costFor hourly = hours × rate', function () {
    $user = User::factory()->create([
        'cost_rate_type'   => CostRateType::Hourly,
        'cost_rate_amount' => 75.0,
    ]);

    expect($user->costFor(2.0))->toBe(150.0);
});

// ── flat-rate cost ────────────────────────────────────────────────────────────

it('flat-rate entry derivedCost returns the fixed amount', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project, 'developer', [
        'cost_rate_type'   => CostRateType::Flat,
        'cost_rate_amount' => 800.0,
    ]);
    $task = timeTask($module, $user);

    $entry = TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 10.0,
        'worked_on'     => '2026-10-01',
    ]);

    expect($entry->derivedCost())->toBe(800.0);
});

it('User::costFor flat returns the flat amount regardless of hours', function () {
    $user = User::factory()->create([
        'cost_rate_type'   => CostRateType::Flat,
        'cost_rate_amount' => 500.0,
    ]);

    expect($user->costFor(99.0))->toBe(500.0);
});

// ── polymorphic morphMany ─────────────────────────────────────────────────────

it('task has timeEntries morphMany', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project);
    $task    = timeTask($module, $user);

    TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 2.0,
        'worked_on'     => '2026-10-01',
    ]);

    expect($task->timeEntries()->count())->toBe(1);
});

it('subtask has timeEntries morphMany', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project);
    $task    = timeTask($module, $user);
    $subtask = Subtask::create(['task_id' => $task->id, 'title' => 'Sub', 'estimate_hours' => 1.0, 'position' => 1]);

    TimeEntry::create([
        'timeable_type' => Subtask::class,
        'timeable_id'   => $subtask->id,
        'user_id'       => $user->id,
        'hours'         => 1.5,
        'worked_on'     => '2026-10-01',
    ]);

    expect($subtask->timeEntries()->count())->toBe(1);
});

// ── null/unconfigured cost rate ──────────────────────────────────────────────

it('null cost_rate_amount yields zero cost', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project, 'developer', [
        'cost_rate_type'   => null,
        'cost_rate_amount' => null,
    ]);
    $task = timeTask($module, $user);

    $entry = TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 5.0,
        'worked_on'     => '2026-10-01',
    ]);

    expect($entry->derivedCost())->toBe(0.0); // Unconfigured rate = zero cost, intentionally
});

// ── flat-rate per-entry contract (aggregation dedup) ────────────────────────

it('flat-rate two entries each return the full fixed amount (per-entry, not summed)', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project, 'developer', [
        'cost_rate_type'   => CostRateType::Flat,
        'cost_rate_amount' => 800.0,
    ]);
    $task = timeTask($module, $user);

    $entry1 = TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 4.0,
        'worked_on'     => '2026-10-01',
    ]);

    $entry2 = TimeEntry::create([
        'timeable_type' => Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $user->id,
        'hours'         => 6.0,
        'worked_on'     => '2026-10-02',
    ]);

    // Each entry returns the full flat amount (800.0), not split or halved.
    // derivedCost() is per-entry. The AGGREGATION layer (Task 12 profit/loss)
    // must count the flat amount ONCE per project, not sum across entries.
    expect($entry1->derivedCost())->toBe(800.0);
    expect($entry2->derivedCost())->toBe(800.0);
});

// ── loggedBetween scope ───────────────────────────────────────────────────────

it('loggedBetween sums hours inside range and excludes outside', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project);
    $task    = timeTask($module, $user);

    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $user->id, 'hours' => 2.0, 'worked_on' => '2026-10-05']);
    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $user->id, 'hours' => 3.0, 'worked_on' => '2026-10-10']);
    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $user->id, 'hours' => 5.0, 'worked_on' => '2026-10-20']); // outside

    $total = TimeEntry::loggedBetween('2026-10-01', '2026-10-15')->sum('hours');

    expect((float) $total)->toBe(5.0); // 2.0 + 3.0
});

it('loggedBetween includes boundary dates', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $user    = timeMember($project);
    $task    = timeTask($module, $user);

    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $user->id, 'hours' => 1.0, 'worked_on' => '2026-10-01']); // from boundary
    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $user->id, 'hours' => 1.0, 'worked_on' => '2026-10-07']); // to boundary
    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $user->id, 'hours' => 1.0, 'worked_on' => '2026-09-30']); // before from
    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $user->id, 'hours' => 1.0, 'worked_on' => '2026-10-08']); // after to

    $total = TimeEntry::loggedBetween('2026-10-01', '2026-10-07')->sum('hours');

    expect((float) $total)->toBe(2.0);
});

// ── developer can log time, cannot edit estimate_hours ────────────────────────

it('developer can log time via Logger component', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $dev     = timeMember($project, 'developer');
    $task    = timeTask($module, $dev);
    $task->assignees()->attach($dev->id);

    Livewire::actingAs($dev)
        ->test(Logger::class, ['timeable' => $task])
        ->set('hours', 2.5)
        ->set('worked_on', '2026-10-01')
        ->call('log');

    expect(TimeEntry::count())->toBe(1);
    expect((float) TimeEntry::first()->hours)->toBe(2.5);
});

it('developer logging time does NOT change estimate_hours', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $dev     = timeMember($project, 'developer');
    $task    = timeTask($module, $dev);
    $task->assignees()->attach($dev->id);

    Livewire::actingAs($dev)
        ->test(Logger::class, ['timeable' => $task])
        ->set('hours', 2.5)
        ->set('worked_on', '2026-10-01')
        ->call('log');

    $task->refresh();
    expect((float) $task->estimate_hours)->toBe(4.0); // unchanged
});

it('developer still cannot updateCostTime after logging time', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $dev     = timeMember($project, 'developer');
    $task    = timeTask($module, $dev);
    $task->assignees()->attach($dev->id);

    expect($dev->can('updateCostTime', $task))->toBeFalse();
});

it('logger log() rejects tampering with a task from another project', function () {
    // Project A + task A
    $projectA = timeProject();
    $moduleA  = timeModule($projectA);
    $ownerA   = timeMember($projectA, 'project_manager');
    $taskA    = timeTask($moduleA, $ownerA);

    // Project B + member B (no access to project A)
    $client   = Client::create(['name' => 'BrandCo', 'currency' => 'USD']);
    $projectB = Project::create(['client_id' => $client->id, 'name' => 'ProjectB', 'status' => 'active']);
    $memberB  = timeMember($projectB, 'developer');

    // memberB mounts the logger legitimately on a project-B task, then tampers
    $taskB = timeTask($moduleA, $ownerA); // create another task so we have a valid mount
    // Give memberB their own task to mount on
    $moduleB = timeModule($projectB);
    $validTask = timeTask($moduleB, $memberB);

    Livewire::actingAs($memberB)
        ->test(Logger::class, ['timeable' => $validTask])
        ->set('timeableId', $taskA->id)   // tamper: point at project-A task
        ->set('hours', 2.0)
        ->set('worked_on', '2026-10-01')
        ->call('log');

    // No time entry must have been created for project-A task
    expect(TimeEntry::where('timeable_id', $taskA->id)->count())->toBe(0);
});

it('Logger lists time per user with a subtotal each', function () {
    $project = timeProject();
    $module  = timeModule($project);
    $devA    = timeMember($project, 'developer');
    $devB    = timeMember($project, 'developer');
    $task    = timeTask($module, $devA);
    $task->assignees()->attach([$devA->id, $devB->id]);

    foreach ([[$devA, 1.5], [$devA, 2.0], [$devB, 4.0]] as [$u, $h]) {
        TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $task->id, 'user_id' => $u->id, 'hours' => $h, 'worked_on' => '2026-10-01']);
    }

    Livewire::actingAs($devA)
        ->test(Logger::class, ['timeable' => $task])
        ->assertSee($devA->name)->assertSee($devB->name)
        ->assertSee('3.5h')->assertSee('4h');
});

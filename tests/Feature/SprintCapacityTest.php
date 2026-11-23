<?php

use App\Livewire\Sprints\Panel;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;
use Livewire\Volt\Volt;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->dev = User::factory()->create(['name' => 'Brijesh', 'weekly_capacity_hours' => 10]);
    $this->dev->assignRole('developer');
});

/** A project with one module and a sprint of `$status` ending in 6 days (one week window). */
function capProject(string $status = 'active'): array
{
    $client = Client::create(['name' => 'Acme '.uniqid(), 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $module = Module::create(['project_id' => $project->id, 'name' => 'Core', 'position' => 1]);
    $sprint = $project->sprints()->create(['name' => 'S1', 'status' => $status, 'starts_on' => today(), 'ends_on' => today()->addDays(6)]);

    return [$project, $module, $sprint];
}

function capTask(Module $m, $sprint, float $hours, User $dev, ?string $stage = null): Task
{
    $t = Task::create(['module_id' => $m->id, 'creator_id' => $dev->id, 'title' => 'T'.uniqid(), 'status' => 'published', 'scope' => 'in',
        'estimate_hours' => $hours, 'sprint_id' => $sprint?->id,
        'board_column_id' => $stage ? $m->project->columns()->where('stage', $stage)->first()->id : null]);
    $t->assignees()->sync([$dev->id]);

    return $t;
}

it('sums open tasks across active sprints of all projects, skipping done and non-active', function () {
    [, $m1, $s1] = capProject();
    [, $m2, $s2] = capProject();
    [, $m3, $s3] = capProject('planned');
    capTask($m1, $s1, 4, $this->dev);
    capTask($m2, $s2, 5, $this->dev);
    capTask($m1, $s1, 7, $this->dev, 'done');
    capTask($m3, $s3, 9, $this->dev);
    capTask($m1, null, 3, $this->dev);

    expect($this->dev->sprintLoad())->toBe(['committed' => 9.0, 'capacity' => 10.0, 'over' => false])
        ->and($this->dev->wouldExceedCapacity(2))->toBeTrue();
});

it('is never over without a capacity', function () {
    [, $m, $s] = capProject();
    capTask($m, $s, 50, $this->dev);
    $this->dev->update(['weekly_capacity_hours' => null]);

    expect($this->dev->sprintLoad())->toBe(['committed' => 50.0, 'capacity' => null, 'over' => false])
        ->and($this->dev->wouldExceedCapacity(100))->toBeFalse();
});

it('warns when moving an assigned task into an active sprint goes over capacity', function () {
    [$project, $m, $s] = capProject();
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $this->dev->id, 'role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);
    capTask($m, $s, 8, $this->dev);
    $t = capTask($m, null, 4, $this->dev);

    Livewire::actingAs($this->admin)->test(Panel::class, ['project' => $project])
        ->call('moveTask', $t->id, $s->id, [$t->id])
        ->assertDispatched('toast', type: 'warning', message: 'Brijesh is at 12h / 10h this sprint');
    expect($t->fresh()->sprint_id)->toBe($s->id);
});

it('saves weekly capacity from the profile form', function () {
    Volt::actingAs($this->dev)->test('profile.update-profile-information-form')
        ->set('weekly_capacity_hours', 30)->call('updateProfileInformation')->assertHasNoErrors();

    expect($this->dev->fresh()->weekly_capacity_hours)->toBe(30.0);
});

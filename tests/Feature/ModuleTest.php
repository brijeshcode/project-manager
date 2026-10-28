<?php

use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Enums\AccessScope;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers ──────────────────────────────────────────────────────────────────

function makeProject(): Project
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'Alpha', 'status' => 'active']);
}

function memberOf(Project $project, string $role = 'developer'): User
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

// ── model tests ───────────────────────────────────────────────────────────────

it('creates a module under a project', function () {
    $project = makeProject();

    $module = Module::create(['project_id' => $project->id, 'name' => 'Authentication', 'position' => 1]);

    expect($module->id)->not->toBeNull();
    expect($module->project_id)->toBe($project->id);
    expect($module->name)->toBe('Authentication');
    expect($module->position)->toBe(1);
});

it('new module gets the next position within its project', function () {
    $project = makeProject();

    $m1 = Module::create(['project_id' => $project->id, 'name' => 'Auth', 'position' => Module::nextPosition($project->id)]);
    $m2 = Module::create(['project_id' => $project->id, 'name' => 'Billing', 'position' => Module::nextPosition($project->id)]);

    expect($m1->position)->toBe(1);
    expect($m2->position)->toBe(2);
});

it('reorder persists new positions', function () {
    $project = makeProject();

    $m1 = Module::create(['project_id' => $project->id, 'name' => 'Auth',    'position' => 1]);
    $m2 = Module::create(['project_id' => $project->id, 'name' => 'Billing', 'position' => 2]);
    $m3 = Module::create(['project_id' => $project->id, 'name' => 'Reports', 'position' => 3]);

    Module::reorder([$m3->id, $m1->id, $m2->id]);

    expect($m3->fresh()->position)->toBe(1);
    expect($m1->fresh()->position)->toBe(2);
    expect($m2->fresh()->position)->toBe(3);
});

it('project has modules relationship', function () {
    $project = makeProject();
    Module::create(['project_id' => $project->id, 'name' => 'Auth',    'position' => 1]);
    Module::create(['project_id' => $project->id, 'name' => 'Billing', 'position' => 2]);

    expect($project->modules()->count())->toBe(2);
});

// ── Livewire Manager tests ────────────────────────────────────────────────────

it('manager allows an active member to see modules', function () {
    $project = makeProject();
    $user    = memberOf($project);

    Module::create(['project_id' => $project->id, 'name' => 'Auth', 'position' => 1]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\Modules\Manager::class, ['project' => $project])
        ->assertSee('Auth');
});

it('manager denies a non-member', function () {
    $project    = makeProject();
    $nonMember  = User::factory()->create();
    $nonMember->assignRole('developer');

    Livewire::actingAs($nonMember)
        ->test(\App\Livewire\Modules\Manager::class, ['project' => $project])
        ->assertForbidden();
});

it('manager allows super_admin', function () {
    $project = makeProject();
    $admin   = User::factory()->create();
    $admin->assignRole('super_admin');

    Module::create(['project_id' => $project->id, 'name' => 'Auth', 'position' => 1]);

    Livewire::actingAs($admin)
        ->test(\App\Livewire\Modules\Manager::class, ['project' => $project])
        ->assertSee('Auth');
});

it('manager createModule adds a module with next position', function () {
    $project = makeProject();
    $user    = memberOf($project);

    Livewire::actingAs($user)
        ->test(\App\Livewire\Modules\Manager::class, ['project' => $project])
        ->set('newName', 'Onboarding')
        ->call('createModule')
        ->assertSee('Onboarding');

    expect(Module::where('project_id', $project->id)->count())->toBe(1);
    expect(Module::where('project_id', $project->id)->first()->position)->toBe(1);
});

it('manager reorderModules persists order', function () {
    $project = makeProject();
    $user    = memberOf($project);

    $m1 = Module::create(['project_id' => $project->id, 'name' => 'A', 'position' => 1]);
    $m2 = Module::create(['project_id' => $project->id, 'name' => 'B', 'position' => 2]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\Modules\Manager::class, ['project' => $project])
        ->call('reorderModules', [$m2->id, $m1->id]);

    expect($m2->fresh()->position)->toBe(1);
    expect($m1->fresh()->position)->toBe(2);
});

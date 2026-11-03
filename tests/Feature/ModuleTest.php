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

if (! function_exists('makeProject')) {
    function makeProject(): Project
    {
        $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
        return Project::create(['client_id' => $client->id, 'name' => 'Alpha', 'status' => 'active']);
    }
}

if (! function_exists('memberOf')) {
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

    Module::reorder([$m3->id, $m1->id, $m2->id], $project->id);

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

it('reorder scoped to project cannot affect another projects modules', function () {
    $projectA = makeProject();
    $projectB = makeProject();

    $a1 = Module::create(['project_id' => $projectA->id, 'name' => 'A1', 'position' => 1]);
    $a2 = Module::create(['project_id' => $projectA->id, 'name' => 'A2', 'position' => 2]);

    $b1 = Module::create(['project_id' => $projectB->id, 'name' => 'B1', 'position' => 1]);
    $b2 = Module::create(['project_id' => $projectB->id, 'name' => 'B2', 'position' => 2]);

    // Attempt to reorder scoped to Project A, injecting Project B IDs
    Module::reorder([$b2->id, $b1->id, $a2->id, $a1->id], $projectA->id);

    // Project B positions must be unchanged
    expect($b1->fresh()->position)->toBe(1);
    expect($b2->fresh()->position)->toBe(2);

    // Project A positions are updated
    expect($a2->fresh()->position)->toBe(3);
    expect($a1->fresh()->position)->toBe(4);
});

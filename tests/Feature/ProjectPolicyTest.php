<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Enums\AccessScope;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('a project member can view the project', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();
    $user->assignRole('developer');

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);

    expect($user->can('view', $project))->toBeTrue();
});

it('a project member can update the project', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();
    $user->assignRole('developer');

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);

    expect($user->can('update', $project))->toBeTrue();
});

it('a non-member is denied view', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();
    $user->assignRole('developer');

    // No ProjectMember record created
    expect($user->can('view', $project))->toBeFalse();
});

it('a non-member is denied update', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();
    $user->assignRole('developer');

    expect($user->can('update', $project))->toBeFalse();
});

it('a client-user of a different client is denied view', function () {
    $clientA = Client::create(['name' => 'Client A', 'currency' => 'USD']);
    $clientB = Client::create(['name' => 'Client B', 'currency' => 'EUR']);

    $projectA = Project::create(['client_id' => $clientA->id, 'name' => 'A Project', 'status' => 'active']);

    // clientB user — not a member of any project under clientA
    $userB = User::factory()->create(['client_id' => $clientB->id]);
    $userB->assignRole('client');

    expect($userB->can('view', $projectA))->toBeFalse();
});

it('a client-user of a different client is denied update', function () {
    $clientA = Client::create(['name' => 'Client A', 'currency' => 'USD']);
    $clientB = Client::create(['name' => 'Client B', 'currency' => 'EUR']);

    $projectA = Project::create(['client_id' => $clientA->id, 'name' => 'A Project', 'status' => 'active']);

    $userB = User::factory()->create(['client_id' => $clientB->id]);
    $userB->assignRole('client');

    expect($userB->can('update', $projectA))->toBeFalse();
});

it('super_admin can view any project', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $admin   = User::factory()->create();
    $admin->assignRole('super_admin');

    expect($admin->can('view', $project))->toBeTrue();
});

it('super_admin can update any project', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $admin   = User::factory()->create();
    $admin->assignRole('super_admin');

    expect($admin->can('update', $project))->toBeTrue();
});

it('project_manager who is a member can view', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $pm      = User::factory()->create();
    $pm->assignRole('project_manager');

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $pm->id,
        'role_on_project' => 'pm',
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);

    expect($pm->can('view', $project))->toBeTrue();
});

// Finding 2: inactive members must be denied
it('an inactive member is denied view', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();
    $user->assignRole('developer');

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => false,
        'removed_at'      => now(),
    ]);

    expect($user->can('view', $project))->toBeFalse();
});

it('an inactive member is denied update', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();
    $user->assignRole('developer');

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => false,
        'removed_at'      => now(),
    ]);

    expect($user->can('update', $project))->toBeFalse();
});

// Finding 3: only super_admin and project_manager can create
it('a client user cannot create a project', function () {
    $client = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $user   = User::factory()->create(['client_id' => $client->id]);
    $user->assignRole('client');

    expect($user->can('create', Project::class))->toBeFalse();
});

it('a developer cannot create a project', function () {
    $user = User::factory()->create();
    $user->assignRole('developer');

    expect($user->can('create', Project::class))->toBeFalse();
});

it('a project_manager can create a project', function () {
    $user = User::factory()->create();
    $user->assignRole('project_manager');

    expect($user->can('create', Project::class))->toBeTrue();
});

it('super_admin can create a project', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    expect($admin->can('create', Project::class))->toBeTrue();
});

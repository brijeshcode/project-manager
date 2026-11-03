<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Enums\AccessScope;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('creates a project under a client', function () {
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);

    $project = Project::create([
        'client_id'      => $client->id,
        'name'           => 'Website Redesign',
        'buffer_percent' => 10.50,
        'start_date'     => '2026-01-01',
        'status'         => 'active',
    ]);

    expect($project->id)->not->toBeNull();
    expect($project->name)->toBe('Website Redesign');
    expect($project->client_id)->toBe($client->id);
    expect((float) $project->buffer_percent)->toBe(10.5);
    expect($project->status)->toBe('active');
});

it('derives currency from client', function () {
    $client = Client::create(['name' => 'Euro Corp', 'currency' => 'EUR']);
    $project = Project::create([
        'client_id' => $client->id,
        'name'      => 'Portal',
        'status'    => 'draft',
    ]);

    expect($project->currency)->toBe('EUR');
});

it('can list projects via client->projects()', function () {
    $client = Client::create(['name' => 'BigCo', 'currency' => 'GBP']);
    Project::create(['client_id' => $client->id, 'name' => 'Project A', 'status' => 'active']);
    Project::create(['client_id' => $client->id, 'name' => 'Project B', 'status' => 'draft']);

    expect($client->projects()->count())->toBe(2);
});

it('attaches a member to a project via ProjectMember', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);

    expect($project->members()->count())->toBe(1);
    expect($project->members()->first()->id)->toBe($user->id);
});

it('casts access_scope as AccessScope enum', function () {
    $client  = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user    = User::factory()->create();

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::Frontend,
        'active'          => true,
    ]);

    $pm = ProjectMember::first();
    expect($pm->access_scope)->toBe(AccessScope::Frontend);
});

// Finding 1: Index query scoping
it('index scoped query does not show other clients projects to a client user', function () {
    $clientA = Client::create(['name' => 'Client A', 'currency' => 'USD']);
    $clientB = Client::create(['name' => 'Client B', 'currency' => 'EUR']);

    $projectA = Project::create(['client_id' => $clientA->id, 'name' => 'A Project', 'status' => 'active']);

    $userB = User::factory()->create(['client_id' => $clientB->id]);
    $userB->assignRole('client');

    // Replicate the Index scoping logic
    $results = Project::with('client')
        ->orderBy('name')
        ->whereHas('members', fn ($q) => $q
            ->where('users.id', $userB->id)
            ->where('project_members.active', true))
        ->get();

    expect($results)->toHaveCount(0);
});

it('index scoped query shows project to an active member', function () {
    $client   = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    $project  = Project::create(['client_id' => $client->id, 'name' => 'App', 'status' => 'active']);
    $user     = User::factory()->create();
    $user->assignRole('developer');

    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => 'developer',
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);

    $results = Project::with('client')
        ->orderBy('name')
        ->whereHas('members', fn ($q) => $q
            ->where('users.id', $user->id)
            ->where('project_members.active', true))
        ->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($project->id);
});

it('index scoped query with no filter shows all projects to super_admin', function () {
    $client = Client::create(['name' => 'Corp', 'currency' => 'USD']);
    Project::create(['client_id' => $client->id, 'name' => 'Alpha', 'status' => 'active']);
    Project::create(['client_id' => $client->id, 'name' => 'Beta', 'status' => 'draft']);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    // super_admin path: no whereHas filter
    $results = Project::with('client')->orderBy('name')->get();

    expect($results)->toHaveCount(2);
});

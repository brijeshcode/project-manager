<?php

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('renders the clients page through the full-page layout', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    actingAs($admin)->get('/clients')->assertOk();
});

it('renders the projects page through the full-page layout', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    actingAs($admin)->get('/projects')->assertOk();
});

it('renders project cards with live progress (health meter) without error', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = \App\Models\Project::create([
        'client_id' => $client->id,
        'name' => 'Acme Site',
        'buffer_percent' => 10,
        'start_date' => now(),
        'status' => 'active',
    ]);
    $module = \App\Models\Module::create(['project_id' => $project->id, 'name' => 'Build', 'position' => 1]);
    $task = \App\Models\Task::create([
        'module_id' => $module->id,
        'creator_id' => $admin->id,
        'title' => 'Homepage',
        'status' => 'published',
        'estimate_hours' => 8,
        'scope' => 'in',
        'board_column' => 'In Progress',
        'position' => 1,
    ]);
    \App\Models\ChecklistItem::create(['checklistable_type' => \App\Models\Task::class, 'checklistable_id' => $task->id, 'title' => 'a', 'is_done' => true, 'position' => 1]);
    \App\Models\ChecklistItem::create(['checklistable_type' => \App\Models\Task::class, 'checklistable_id' => $task->id, 'title' => 'b', 'is_done' => false, 'position' => 2]);
    \App\Models\TimeEntry::create(['timeable_type' => \App\Models\Task::class, 'timeable_id' => $task->id, 'user_id' => $admin->id, 'hours' => 4, 'worked_on' => now()]);

    actingAs($admin)->get('/projects')
        ->assertOk()
        ->assertSee('Acme Site')
        ->assertSee('Checklist'); // health meter rendered
});

it('project show page displays estimated and logged hours', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $client  = Client::create(['name' => 'TL Corp', 'currency' => 'USD']);
    $project = \App\Models\Project::create([
        'client_id' => $client->id, 'name' => 'TL Project',
        'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active',
    ]);
    $module = \App\Models\Module::create(['project_id' => $project->id, 'name' => 'Core', 'position' => 1]);
    $task   = \App\Models\Task::create([
        'module_id'      => $module->id,
        'creator_id'     => $admin->id,
        'title'          => 'Work',
        'status'         => 'published',
        'estimate_hours' => 16,
        'scope'          => 'in',
        'board_column'   => 'Backlog',
        'position'       => 1,
    ]);
    \App\Models\TimeEntry::create([
        'timeable_type' => \App\Models\Task::class,
        'timeable_id'   => $task->id,
        'user_id'       => $admin->id,
        'hours'         => 4,
        'worked_on'     => now(),
    ]);

    actingAs($admin)->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Estimated hours')
        ->assertSee('Logged hours');
});

it('project workspace embeds time logger for tasks', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $client  = Client::create(['name' => 'Logger Corp', 'currency' => 'USD']);
    $project = \App\Models\Project::create([
        'client_id' => $client->id, 'name' => 'Logger Project',
        'buffer_percent' => 0, 'status' => 'active',
    ]);
    $module = \App\Models\Module::create(['project_id' => $project->id, 'name' => 'Core', 'position' => 1]);
    \App\Models\Task::create([
        'module_id' => $module->id, 'creator_id' => $admin->id,
        'title' => 'Loggable Task', 'status' => 'published',
        'scope' => 'in', 'board_column' => 'Backlog', 'position' => 1,
    ]);

    actingAs($admin)->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Log time');
});

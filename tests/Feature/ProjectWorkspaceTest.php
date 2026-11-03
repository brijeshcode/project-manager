<?php

use App\Livewire\Projects\Show;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function wsProject(): Project
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);

    return Project::create([
        'client_id' => $client->id,
        'name' => 'Site',
        'buffer_percent' => 0,
        'start_date' => now(),
        'status' => 'active',
    ]);
}

function wsManager(Project $project): User
{
    $user = User::factory()->create();
    $user->assignRole('project_manager');
    $project->members()->attach($user->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);

    return $user;
}

it('lets a project member add a module, task, subtask and checklist item', function () {
    $project = wsProject();
    $user = wsManager($project);

    Livewire::actingAs($user)->test(\App\Livewire\Modules\Create::class, ['project' => $project])
        ->set('name', 'Checkout')
        ->call('save');

    $module = $project->modules()->first();
    expect($module)->not->toBeNull()
        ->and($module->name)->toBe('Checkout');

    Livewire::actingAs($user)->test(Show::class, ['project' => $project])
        ->set("newTaskTitle.{$module->id}", 'Build cart')
        ->call('addTask', $module->id);

    $task = Task::where('module_id', $module->id)->first();
    expect($task->title)->toBe('Build cart')
        ->and($task->status->value)->toBe('published');

    Livewire::actingAs($user)->test(Show::class, ['project' => $project])
        ->set("newSubtaskTitle.{$task->id}", 'Cart API')
        ->call('addSubtask', $task->id)
        ->set("newChecklistTitle.{$task->id}", 'Write test')
        ->call('addChecklistItem', $task->id);

    expect($task->subtasks()->count())->toBe(1)
        ->and($task->checklistItems()->count())->toBe(1);

    $item = $task->checklistItems()->first();
    expect($item->is_done)->toBeFalse();

    Livewire::actingAs($user)->test(Show::class, ['project' => $project])
        ->call('toggleChecklistItem', $item->id);

    expect($item->fresh()->is_done)->toBeTrue();
});

it('saves descriptions for the project form, modules and tasks', function () {
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    // Project form description
    Livewire::actingAs($admin)->test(\App\Livewire\Projects\Form::class)
        ->set('client_id', $client->id)
        ->set('name', 'Site')
        ->set('description', 'The marketing site rebuild.')
        ->set('status', 'active')
        ->call('save');

    $project = Project::where('name', 'Site')->firstOrFail();
    expect($project->description)->toBe('The marketing site rebuild.');

    $project->members()->attach($admin->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);

    // Module description at create, then edit
    Livewire::actingAs($admin)->test(\App\Livewire\Modules\Create::class, ['project' => $project])
        ->set('name', 'Checkout')
        ->set('description', 'Handles payment')
        ->call('save');
    $component = Livewire::actingAs($admin)->test(Show::class, ['project' => $project]);

    $module = $project->modules()->first();
    expect($module->description)->toBe('Handles payment');

    $component->set("moduleDescriptions.{$module->id}", 'Payment + receipts')
        ->call('saveModuleDescription', $module->id);
    expect($module->fresh()->description)->toBe('Payment + receipts');

    // Task description
    $component->set("newTaskTitle.{$module->id}", 'Stripe')
        ->call('addTask', $module->id);
    $task = Task::where('module_id', $module->id)->firstOrFail();

    $component->set("taskDescriptions.{$task->id}", 'Wire up Stripe Checkout.')
        ->call('saveTaskDescription', $task->id);
    expect($task->fresh()->description)->toBe('Wire up Stripe Checkout.');
});

it('forbids a non-member from opening the project workspace', function () {
    $project = wsProject();
    $outsider = User::factory()->create();
    $outsider->assignRole('developer');

    Livewire::actingAs($outsider)->test(Show::class, ['project' => $project])
        ->assertForbidden();
});

it('blocks adding a task to a module from another project', function () {
    $projectA = wsProject();
    $user = wsManager($projectA);

    $projectB = wsProject();
    $moduleB = Module::create(['project_id' => $projectB->id, 'name' => 'Other', 'position' => 1]);

    // The guard resolves the module through $this->project, so a foreign id 404s.
    expect(fn () => Livewire::actingAs($user)->test(Show::class, ['project' => $projectA])
        ->set("newTaskTitle.{$moduleB->id}", 'Injected')
        ->call('addTask', $moduleB->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(Task::where('module_id', $moduleB->id)->count())->toBe(0);
});

it('workspace hides draft tasks created by other users', function () {
    $project = wsProject();
    $manager = wsManager($project);

    // Another developer creates a draft task (not the manager)
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    $project->members()->attach($dev->id, ['role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);

    $module = $project->modules()->create(['name' => 'Core', 'position' => 1]);

    Task::create([
        'module_id'    => $module->id,
        'creator_id'   => $dev->id,
        'title'        => 'Secret Draft',
        'status'       => 'draft',
        'scope'        => 'in',
        'position'     => 1,
    ]);

    // Manager (not the creator) should NOT see it
    Livewire::actingAs($manager)
        ->test(\App\Livewire\Projects\Show::class, ['project' => $project])
        ->assertDontSee('Secret Draft');
});

<?php

use App\Enums\AccessScope;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function tfAdminUser(): User
{
    $u = User::factory()->create();
    $u->assignRole('super_admin');
    return $u;
}

function tfProject(): Project
{
    $client = Client::create(['name' => 'TF Client', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'TF Project', 'status' => 'active']);
}

function tfModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Core', 'position' => 1]);
}

function tfMember(Project $project, string $role = 'developer'): User
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

it('task form syncs labels and assignees when saving', function () {
    $admin    = tfAdminUser();
    $project  = tfProject();
    $module   = tfModule($project);
    $task     = Task::create([
        'module_id'    => $module->id,
        'creator_id'   => $admin->id,
        'title'        => 'Original title',
        'status'       => 'published',
        'scope'        => 'in',
        'position'     => 1,
    ]);
    $label    = Label::create(['name' => 'Bug', 'color' => '#ef4444']);
    $assignee = tfMember($project);

    Livewire::actingAs($admin)
        ->test(\App\Livewire\Tasks\Form::class, ['task' => $task])
        ->set('labelIds', [$label->id])
        ->set('assigneeIds', [$assignee->id])
        ->call('save');

    expect($task->fresh()->labels()->count())->toBe(1)
        ->and($task->fresh()->assignees()->count())->toBe(1);
});

it('task form hides scope and pricing card from developers', function () {
    $project  = tfProject();
    $module   = tfModule($project);
    $dev      = tfMember($project, 'developer');
    $task     = Task::create([
        'module_id'    => $module->id,
        'creator_id'   => $dev->id,
        'title'        => 'Dev task',
        'status'       => 'published',
        'scope'        => 'in',
        'position'     => 1,
    ]);

    Livewire::actingAs($dev)
        ->test(\App\Livewire\Tasks\Form::class, ['task' => $task])
        ->assertDontSee('Scope &amp; pricing');
});

it('task form returns 403 for a non-member', function () {
    $project  = tfProject();
    $module   = tfModule($project);
    $creator  = tfAdminUser();
    $task     = Task::create([
        'module_id'    => $module->id,
        'creator_id'   => $creator->id,
        'title'        => 'Restricted task',
        'status'       => 'published',
        'scope'        => 'in',
        'position'     => 1,
    ]);
    $outsider = User::factory()->create();
    $outsider->assignRole('developer');

    Livewire::actingAs($outsider)
        ->test(\App\Livewire\Tasks\Form::class, ['task' => $task])
        ->assertForbidden();
});

it('task form page is reachable via route for managers', function () {
    $admin   = tfAdminUser();
    $project = tfProject();
    $module  = tfModule($project);
    $task    = Task::create([
        'module_id'    => $module->id,
        'creator_id'   => $admin->id,
        'title'        => 'Test task',
        'status'       => 'published',
        'scope'        => 'in',
        'position'     => 1,
    ]);

    $this->actingAs($admin)
        ->get(route('tasks.edit', $task))
        ->assertOk();
});

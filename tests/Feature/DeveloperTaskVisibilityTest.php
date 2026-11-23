<?php

use App\Livewire\Tasks\Board;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Services\ProgressService;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->dev = User::factory()->create();
    $this->dev->assignRole('developer');
    $this->pm = User::factory()->create();
    $this->pm->assignRole('project_manager');

    $this->project = Project::create(['client_id' => Client::create(['name' => 'Acme', 'currency' => 'USD'])->id, 'name' => 'Alpha', 'status' => 'active']);
    ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->dev->id, 'role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);
    ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->pm->id, 'role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);
    $m = Module::create(['project_id' => $this->project->id, 'name' => 'Core', 'position' => 1]);
    $col = $this->project->columns()->where('stage', 'not_started')->first()->id;
    $make = fn (string $t) => Task::create(['module_id' => $m->id, 'creator_id' => $admin->id, 'title' => $t, 'status' => 'published', 'scope' => 'in', 'estimate_hours' => 2, 'board_column_id' => $col]);

    $this->own = $make('Own task');
    $this->own->assignees()->attach($this->dev->id);
    $this->viaSub = $make('Parent task');
    $this->viaSub->subtasks()->create(['title' => 'My sub', 'position' => 1])->assignees()->attach($this->dev->id);
    $this->other = $make('Unassigned task');
    $this->module = $m;
});

it('limits a project developer to tasks assigned to them or to one of their subtasks', function () {
    expect(Task::visibleTo($this->dev)->pluck('id')->sort()->values()->all())->toBe([$this->own->id, $this->viaSub->id]);

    Livewire::actingAs($this->dev)->test(Board::class, ['project' => $this->project])
        ->assertSee('Own task')->assertSee('Parent task')->assertDontSee('Unassigned task');

    $this->actingAs($this->dev)->get(route('tasks.show', $this->own))->assertOk();
    $this->actingAs($this->dev)->get(route('tasks.show', $this->viaSub))->assertOk();
    $this->actingAs($this->dev)->get(route('tasks.show', $this->other))->assertNotFound();
    expect($this->dev->can('view', $this->other))->toBeFalse();

    $this->actingAs($this->dev);
    expect(app(ProgressService::class)->stats($this->project)['tasks'])->toBe(2);
});

it('still shows every task to the project manager', function () {
    expect(Task::visibleTo($this->pm)->count())->toBe(3);

    Livewire::actingAs($this->pm)->test(Board::class, ['project' => $this->project])->assertSee('Unassigned task');
    $this->actingAs($this->pm)->get(route('tasks.show', $this->other))->assertOk();
    expect(app(ProgressService::class)->stats($this->project)['tasks'])->toBe(3);
});

<?php

use App\Livewire\Projects\Form;
use App\Livewire\Projects\Show;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $this->project = Project::create(['client_id' => $this->client->id, 'name' => 'P', 'start_date' => '2026-01-01', 'status' => 'active']);
});

it('sets the first deadline without a reason and does not count it as an extension', function () {
    $this->project->setDeadline('2026-03-01', $this->owner);

    expect($this->project->fresh()->deadline->toDateString())->toBe('2026-03-01')
        ->and($this->project->deadlineChanges()->count())->toBe(1)
        ->and($this->project->deadlineChanges()->first()->old_deadline)->toBeNull()
        ->and($this->project->extensionCount())->toBe(0);
});

it('requires a reason to change an existing deadline and ignores no-ops', function () {
    $this->project->setDeadline('2026-03-01', $this->owner);

    expect(fn () => $this->project->setDeadline('2026-04-01', $this->owner, '  '))->toThrow(ValidationException::class);
    $this->project->setDeadline('2026-03-01', $this->owner);   // unchanged: no reason needed, no log
    expect($this->project->deadlineChanges()->count())->toBe(1);
});

it('counts only later moves as extensions', function () {
    $this->project->setDeadline('2026-03-01', $this->owner);
    $this->project->setDeadline('2026-04-01', $this->owner, 'scope');
    $this->project->setDeadline('2026-03-15', $this->owner, 'pulled in');

    expect($this->project->deadlineChanges()->count())->toBe(3)->and($this->project->extensionCount())->toBe(1);
});

it('stamps completed_at on completion and clears it when reopened', function () {
    $this->project->update(['status' => 'completed']);
    expect($this->project->fresh()->completed_at->toDateString())->toBe(today()->toDateString());

    $this->project->update(['status' => 'active']);
    expect($this->project->fresh()->completed_at)->toBeNull();
});

it('reports the deadline status', function () {
    $this->project->setDeadline(today()->addDays(30), $this->owner);
    expect($this->project->deadlineStatus()[0])->toBe('On track');

    $this->project->setDeadline(today()->subDay(), $this->owner, 'x');
    expect($this->project->deadlineStatus()[0])->toBe('Overdue');

    $this->project->update(['status' => 'completed', 'completed_at' => today()->subDays(4)]);
    expect($this->project->deadlineStatus()[0])->toBe('Finished 3 days early');
    $this->project->setDeadline(today()->subDays(6), $this->owner, 'y');
    expect($this->project->fresh()->deadlineStatus()[0])->toBe('Finished 2 days late');
    $this->project->update(['completed_at' => today()->subDays(6)]);
    expect($this->project->deadlineStatus()[0])->toBe('Finished on time');
});

it('flags a project at risk when the estimate passes the deadline', function () {
    // no tasks: the estimate falls on the start date, so a start after the deadline is "at risk"
    $this->project->update(['start_date' => today()->addDays(10)]);
    $this->project->setDeadline(today()->addDays(5), $this->owner);

    expect($this->project->deadlineStatus()[0])->toStartWith('At risk — estimate is');
});

it('needs no reason on create but requires one when editing a deadline', function () {
    Livewire::actingAs($this->owner)->test(Form::class)->set('client_id', $this->client->id)->set('name', 'New')
        ->set('initial_budget', '0')->set('addons_amount', '0')->set('deadline', today()->addMonth()->toDateString())->call('save')->assertHasNoErrors();
    $new = Project::where('name', 'New')->first();
    expect($new->deadline->toDateString())->toBe(today()->addMonth()->toDateString())->and($new->deadlineChanges()->count())->toBe(1);

    $edit = fn () => Livewire::actingAs($this->owner)->test(Form::class, ['project' => $new]);
    $edit()->set('deadline', today()->addMonths(2)->toDateString())->call('save')->assertHasErrors('reason');
    $edit()->set('deadline', today()->addMonths(2)->toDateString())->set('reason', 'Client delay')->call('save')->assertHasNoErrors();
    expect($new->fresh()->deadline->toDateString())->toBe(today()->addMonths(2)->toDateString())->and($new->extensionCount())->toBe(1);
});

it('rejects a deadline before the start date', function () {
    Livewire::actingAs($this->owner)->test(Form::class, ['project' => $this->project])
        ->set('deadline', '2025-12-01')->call('save')->assertHasErrors('deadline');
});

it('changes the deadline inline from the show page', function () {
    $this->project->setDeadline('2026-03-01', $this->owner);
    $t = Livewire::actingAs($this->owner)->test(Show::class, ['project' => $this->project]);

    $t->set('newDeadline', '2026-05-01')->call('changeDeadline')->assertHasErrors('deadlineReason');
    $t->set('deadlineReason', 'Re-planned')->call('changeDeadline')->assertHasNoErrors()->assertSee('Re-planned');
    expect($this->project->fresh()->deadline->toDateString())->toBe('2026-05-01');
});

it('project page shows tabs, defaults to details, and gates economics', function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $c = \App\Models\Client::create(['name' => 'T', 'currency' => 'USD']);
    $p = \App\Models\Project::create(['client_id' => $c->id, 'name' => 'Tabbed', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $admin = \App\Models\User::factory()->create()->assignRole('super_admin');
    $pm = \App\Models\User::factory()->create()->assignRole('project_manager');
    $p->members()->attach($pm->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);

    $this->actingAs($admin)->get(route('projects.show', $p))->assertOk()->assertSee('Project health')->assertDontSee('Cost of logged hours');
    foreach (['modules' => 'No modules yet', 'board' => 'Backlog', 'economics' => 'Cost of logged hours'] as $tab => $see) {
        $this->actingAs($admin)->get(route('projects.show', [$p, 'tab' => $tab]))->assertOk()->assertSee($see);
    }
    $this->actingAs($pm)->get(route('projects.show', [$p, 'tab' => 'economics']))->assertOk()->assertDontSee('Cost of logged hours')->assertSee('Project health');
});

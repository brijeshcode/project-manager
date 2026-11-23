<?php

use App\Livewire\Projects\Members;
use App\Livewire\Team\Index;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('admin creates a developer and a reset link is sent', function () {
    Notification::fake();
    $this->actingAs($this->admin)->get('/team')->assertOk()->assertSee('Team');

    Livewire::actingAs($this->admin)->test(Index::class)
        ->call('create')
        ->set('name', 'Dev One')->set('email', 'dev1@example.com')->set('role', 'developer')->set('capacity', '30')
        ->call('save')->assertHasNoErrors();

    $dev = User::where('email', 'dev1@example.com')->firstOrFail();
    expect($dev->hasRole('developer'))->toBeTrue()
        ->and($dev->weekly_capacity_hours)->toBe(30.0)
        ->and($dev->active)->toBeTrue();
    Notification::assertSentTo($dev, ResetPassword::class);
});

test('email must be unique and admin cannot deactivate themselves', function () {
    Livewire::actingAs($this->admin)->test(Index::class)
        ->call('create')->set('name', 'X')->set('email', $this->admin->email)->call('save')->assertHasErrors('email')
        ->call('toggleActive', $this->admin->id);
    expect($this->admin->fresh()->active)->toBeTrue();
});

test('non-admin gets 403 on team page', function () {
    $pm = User::factory()->create();
    $pm->assignRole('project_manager');
    $this->actingAs($pm)->get('/team')->assertForbidden();
});

test('adding a member makes them assignable; deactivating does not', function () {
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $dev = User::factory()->create();
    $dev->assignRole('developer');

    $c = Livewire::actingAs($this->admin)->test(Members::class, ['project' => $project])
        ->set('userId', $dev->id)->set('role', 'developer')->set('scope', 'frontend')
        ->call('add')->assertHasNoErrors();

    $assignable = fn () => $project->members()->wherePivot('active', true)->pluck('users.id')->all();
    expect($assignable())->toContain($dev->id)
        ->and($project->members()->find($dev->id)->pivot->access_scope)->toBe('frontend');

    $this->actingAs($this->admin)->get(route('projects.show', $project))->assertOk()->assertSee('Members')->assertSee($dev->name);

    $c->call('remove', $dev->id);
    expect($assignable())->not->toContain($dev->id)
        ->and($project->members()->whereKey($dev->id)->exists())->toBeTrue(); // row kept for history
});

test('inactive user cannot log in', function () {
    $user = User::factory()->create(['active' => false]);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)->set('form.password', 'password')
        ->call('login')->assertHasErrors('form.email');

    $this->assertGuest();
});

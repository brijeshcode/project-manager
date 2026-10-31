<?php

use App\Enums\BillingMode;
use App\Livewire\Projects\Show;
use App\Models\Client;
use App\Models\Module;
use App\Models\ModuleBudgetChange;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function bcModule(string $mode = 'fixed'): Module
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => $mode]);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);

    return Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'position' => 1]);
}

function bcUser(string $role, ?Project $project = null): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $project?->members()->attach($u->id, ['role_on_project' => $role, 'access_scope' => 'all', 'active' => true]);

    return $u;
}

it('defaults client billing mode to fixed and casts to enum', function () {
    $c = Client::create(['name' => 'A', 'currency' => 'USD']);
    expect($c->fresh()->billing_mode)->toBe(BillingMode::Fixed);
});

it('computes budgetCents by billing mode', function () {
    $m = bcModule('hourly');
    expect($m->budgetCents())->toBeNull();
    $m->update(['budget_hours' => 10, 'hourly_rate_cents' => 5000]);
    expect($m->fresh()->budgetCents())->toBe(50000);

    $f = bcModule('fixed');
    expect($f->budgetCents())->toBeNull();
    $f->update(['budget_amount_cents' => 100000]);
    expect($f->fresh()->budgetCents())->toBe(100000);
});

it('logs one change row per changed field', function () {
    $m = bcModule('hourly');
    $u = bcUser('super_admin');
    $m->updateBudget(['budget_hours' => 10, 'hourly_rate_cents' => 5000], $u, '');
    $m->updateBudget(['budget_hours' => 15, 'hourly_rate_cents' => 5000], $u, 'Scope grew');

    $rows = ModuleBudgetChange::where('module_id', $m->id)->where('reason', 'Scope grew')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->field)->toBe('budget_hours')
        ->and((float) $rows[0]->old)->toBe(10.0)
        ->and((float) $rows[0]->new)->toBe(15.0)
        ->and($rows[0]->user_id)->toBe($u->id)
        ->and((float) $m->fresh()->budget_hours)->toBe(15.0);
});

it('requires a reason when changing an existing budget value', function () {
    $m = bcModule('hourly');
    $u = bcUser('super_admin');
    $m->updateBudget(['budget_hours' => 10], $u, '');
    expect(fn () => $m->updateBudget(['budget_hours' => 12], $u, ''))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect((float) $m->fresh()->budget_hours)->toBe(10.0);
});

it('gates manage-money to super admin', function () {
    expect(Gate::forUser(bcUser('developer'))->allows('manage-money'))->toBeFalse()
        ->and(Gate::forUser(bcUser('project_manager'))->allows('manage-money'))->toBeFalse()
        ->and(Gate::forUser(bcUser('super_admin'))->allows('manage-money'))->toBeTrue();
});

it('forbids a developer from the budget action', function () {
    $m = bcModule();
    $dev = bcUser('developer', $m->project);

    Livewire::actingAs($dev)->test(Show::class, ['project' => $m->project])
        ->set("budget.{$m->id}.amount", '100')
        ->call('saveBudget', $m->id)
        ->assertForbidden();
    expect($m->fresh()->budget_amount_cents)->toBeNull();
});

it('lets the owner save a budget in major units and hides fields from others', function () {
    $m = bcModule();
    $admin = bcUser('super_admin');

    Livewire::actingAs($admin)->test(Show::class, ['project' => $m->project])
        ->set("budget.{$m->id}.amount", '1,000.50')
        ->set("budget.{$m->id}.deadline", '2026-12-01')
        ->call('saveBudget', $m->id)
        ->assertHasNoErrors()
        ->assertSee('Budget');
    expect($m->fresh()->budget_amount_cents)->toBe(100050)
        ->and($m->fresh()->deadline->toDateString())->toBe('2026-12-01');

    $pm = bcUser('project_manager', $m->project);
    Livewire::actingAs($pm)->test(Show::class, ['project' => $m->project])
        ->assertDontSee('saveBudget');
});

it('shows a billing mode select on the client form', function () {
    $admin = bcUser('super_admin');
    Livewire::actingAs($admin)->test(\App\Livewire\Clients\Form::class)
        ->set('name', 'X')->set('currency', 'usd')->set('billing_mode', 'hourly')
        ->call('save');
    expect(Client::where('name', 'X')->first()->billing_mode)->toBe(BillingMode::Hourly);
});

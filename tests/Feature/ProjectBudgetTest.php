<?php

use App\Livewire\Projects\Form;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Services\ExchangeRates;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    (new ExchangeRates)->setRate('USD', '80', $this->owner);
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
});

it('calculates the budget from the modules, keeps local twins, and accepts a null aim', function () {
    $p = Project::create(['client_id' => $this->client->id, 'name' => 'P']);
    expect((string) $p->final_budget)->toBe('0.00')->and($p->aim)->toBeNull();

    Module::create(['project_id' => $p->id, 'name' => 'A', 'position' => 1, 'budget_amount' => '1000.10']);
    Module::create(['project_id' => $p->id, 'name' => 'B', 'position' => 2, 'budget_amount' => '250.20']);
    $p->refresh();
    expect((string) $p->initial_budget)->toBe('1250.30')->and((string) $p->addons_amount)->toBe('0.00')->and((string) $p->final_budget)->toBe('1250.30')
        ->and((float) $p->exchange_rate)->toBe(80.0)
        ->and((string) $p->initial_budget_local)->toBe('100024.00')->and((string) $p->final_budget_local)->toBe('100024.00');

    $p->update(['aim' => 'Fix checkout']);
    expect((string) $p->fresh()->final_budget)->toBe('1250.30')->and($p->fresh()->aim)->toBe('Fix checkout');
});

it('shows the budget read-only on the form (a new project starts at 0) and saves the aim', function () {
    Livewire::actingAs($this->owner)->test(Form::class)->assertSeeHtml('data-test="calculated-budget"')->assertSeeHtml('readonly')
        ->set('client_id', $this->client->id)->set('name', 'Web')->set('aim', 'Go live')->call('save')->assertHasNoErrors();
    $p = Project::where('name', 'Web')->first();
    expect((string) $p->final_budget)->toBe('0.00')->and($p->aim)->toBe('Go live');

    Livewire::actingAs($this->owner)->test(Form::class)->set('client_id', $this->client->id)->set('name', '')->call('save')->assertHasErrors('name');
});

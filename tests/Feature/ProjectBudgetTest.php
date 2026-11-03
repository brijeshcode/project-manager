<?php

use App\Livewire\Projects\Form;
use App\Models\Client;
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

it('computes final budget and local amounts, and accepts a null aim', function () {
    $p = Project::create(['client_id' => $this->client->id, 'name' => 'P', 'initial_budget' => '1000.10', 'addons_amount' => '250.20']);
    expect((string) $p->final_budget)->toBe('1250.30')
        ->and((string) $p->exchange_rate)->toBe('80.000000')
        ->and((string) $p->initial_budget_local)->toBe('80008.00')
        ->and((string) $p->final_budget_local)->toBe('100024.00')
        ->and($p->aim)->toBeNull();

    $p->update(['addons_amount' => '0', 'aim' => 'Fix checkout']);
    expect((string) $p->fresh()->final_budget)->toBe('1000.10')->and($p->fresh()->aim)->toBe('Fix checkout');
});

it('saves budgets and aim from the form and rejects bad amounts', function () {
    Livewire::actingAs($this->owner)->test(Form::class)->set('client_id', $this->client->id)->set('name', 'Web')
        ->set('initial_budget', '500')->set('addons_amount', '1,000.5')->set('aim', 'Go live')->call('save')->assertHasNoErrors();
    expect((string) Project::where('name', 'Web')->first()->final_budget)->toBe('1500.50');

    Livewire::actingAs($this->owner)->test(Form::class)->set('client_id', $this->client->id)->set('name', 'Bad')
        ->set('initial_budget', 'abc')->call('save')->assertHasErrors('initial_budget');
});

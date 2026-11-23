<?php

use App\Livewire\Clients\Form;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('excludes internal clients from billable and saves the flag from the form', function () {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('super_admin');
    $billed = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Mine')->set('currency', 'USD')->set('is_internal', true)
        ->call('save')->assertHasNoErrors();

    $mine = Client::where('name', 'Mine')->first();
    expect($mine->is_internal)->toBeTrue()
        ->and(Client::billable()->pluck('id')->all())->toBe([$billed->id]);
});

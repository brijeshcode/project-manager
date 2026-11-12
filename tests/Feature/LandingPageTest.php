<?php

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RoleSeeder;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
});

it('shows the landing page with a login link to guests', function () {
    $this->get('/')->assertOk()->assertSee(route('login'), false)->assertSee('Log in');
});

it('sends a signed-in admin from / to the dashboard', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get('/')->assertRedirect(route('dashboard'));
});

it('sends a client from the dashboard to the portal', function () {
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $user = User::factory()->create(['client_id' => $client->id]);
    $user->assignRole('client');

    $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('portal.dashboard'));
});

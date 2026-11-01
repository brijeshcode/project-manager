<?php

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('shows the full menu to a super admin', function () {
    $u = User::factory()->create();
    $u->assignRole('super_admin');

    $r = actingAs($u)->get('/dashboard');
    $r->assertOk();
    $r->assertSeeInOrder(['Dashboard', 'Clients', 'Projects', 'Invoices', 'Payments', 'Recurring', 'Bank accounts', 'Settings', 'Settings', 'Profile']);
    $r->assertSee('Sign out')->assertSee('Dark mode')->assertSee($u->email);
});

it('hides money and client menus from a developer', function () {
    $u = User::factory()->create();
    $u->assignRole('developer');

    $r = actingAs($u)->get('/dashboard');
    $r->assertOk();
    $r->assertSee('Dashboard')->assertSee('Projects');
    $r->assertDontSee('Clients')->assertDontSee('Invoices')->assertDontSee('Bank accounts');
    $r->assertSee('Sign out')->assertSee('Dark mode')->assertSee($u->email);
});

it('shows the portal menu to a client user', function () {
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $u = User::factory()->create(['client_id' => $client->id]);
    $u->assignRole('client');

    $r = actingAs($u)->get('/portal');
    $r->assertOk();
    $r->assertSee('Dashboard')->assertSee('Invoices')->assertSee('Payments');
    $r->assertDontSee('Clients')->assertDontSee('Recurring')->assertDontSee('Bank accounts');
    $r->assertSee('Sign out')->assertSee('Dark mode')->assertSee($u->email);
});

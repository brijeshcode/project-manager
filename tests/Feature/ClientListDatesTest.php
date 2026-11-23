<?php

use App\Livewire\Clients\Index;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super_admin');
});

it('lists active clients first with type, contact details and stats', function () {
    $gone = Client::create(['name' => 'Aardvark Ltd', 'currency' => 'USD', 'contact_email' => 'a@x.test']);
    Client::create(['name' => 'Zebra Co', 'currency' => 'USD', 'type' => 'individual']);
    $gone->update(['status' => 'inactive', 'portal_blocked_at' => now()]);

    Livewire::actingAs($this->admin)->test(Index::class)->set('status', 'all')
        ->assertSeeInOrder(['Zebra Co', 'Aardvark Ltd'])
        ->assertSee('a@x.test')
        ->assertSeeHtml('data-test="client-type"')
        ->assertSeeHtml('data-test="client-stats"')
        ->assertDontSee('Worked together')
        ->assertSeeHtml('data-test="portal-blocked"');
});

<?php

use App\Livewire\Clients\Index;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super_admin');
});

it('lists active clients first and shows the period worked together', function () {
    $this->travelTo('2026-03-10');
    $gone = Client::create(['name' => 'Aardvark Ltd', 'currency' => 'USD']);
    $live = Client::create(['name' => 'Zebra Co', 'currency' => 'USD']);
    Project::create(['client_id' => $live->id, 'name' => 'Old', 'status' => 'active', 'start_date' => '2025-01-15']);
    $this->travelTo('2026-11-05');
    $gone->update(['status' => 'inactive', 'portal_blocked_at' => now()]);

    Livewire::actingAs($this->admin)->test(Index::class)
        ->assertSeeInOrder(['Zebra Co', 'Aardvark Ltd'])
        ->assertSee('Jan 2025 – Present')
        ->assertSee('Mar 2026 – Nov 2026')
        ->assertSee('Inactive since Nov 5, 2026')
        ->assertSeeHtml('data-test="portal-blocked"');
});

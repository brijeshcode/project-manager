<?php

use App\Livewire\Invoices\Create;
use App\Models\Client;
use App\Models\Project;
use App\Support\DashboardMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(\Database\Seeders\RoleSeeder::class));

it('excludes internal clients from dashboard ranking, totals and the invoice client dropdown', function () {
    $real = Client::create(['name' => 'Acme', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    $mine = Client::create(['name' => 'Me Inc', 'currency' => 'INR', 'billing_mode' => 'fixed', 'is_internal' => true]);
    Project::create(['client_id' => $mine->id, 'name' => 'Side project', 'status' => 'active']);

    $ranked = collect(DashboardMoney::clientRanking())->pluck('client.id');
    expect($ranked)->toContain($real->id)->not->toContain($mine->id)
        ->and(collect(DashboardMoney::topClients(now()->subYear(), now()))->pluck('client.id'))->not->toContain($mine->id)
        ->and(Client::billable()->count())->toBe(1);

    $this->actingAs(\App\Models\User::factory()->create()->assignRole('super_admin'));
    expect(Livewire::test(Create::class)->viewData('clients')->pluck('id'))->toEqual(collect([$real->id]));
});

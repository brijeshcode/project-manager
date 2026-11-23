<?php

use App\Livewire\Tax\Index;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Support\IndiaTax;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('slabs: rebate, marginal relief, cess', function () {
    expect(IndiaTax::tax(1200000, 'new', 2025)['total'])->toBe(0)
        ->and(IndiaTax::tax(1250000, 'new', 2025)['tax'])->toBe(50000)
        ->and(IndiaTax::tax(2400000, 'new', 2025)['total'])->toBe(312000)
        ->and(IndiaTax::tax(1000000, 'old', 2025)['tax'])->toBe(112500)
        ->and(IndiaTax::tax(500000, 'old', 2025)['total'])->toBe(0)
        ->and(IndiaTax::tax(700000, 'new', 2024)['total'])->toBe(0);
});

test('tax page is admin only and sums client TDS for the financial year', function () {
    $this->seed(RoleSeeder::class);
    $this->get(route('tax.index'))->assertRedirect();
    $this->actingAs(User::factory()->create()->assignRole('client'))->get(route('tax.index'))->assertForbidden();

    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    $client = Client::create(['name' => 'Acme', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    $cur = \App\Services\LocalCurrency::code();
    $fy = today()->month >= 4 ? today()->year : today()->year - 1;
    Payment::create(['client_id' => $client->id, 'paid_on' => "$fy-06-01", 'currency' => $cur, 'amount_cents' => 10000000, 'exchange_rate' => '1', 'local_currency' => 'INR', 'local_amount_cents' => 10000000, 'deductions_cents' => 1000000, 'type' => 'project']);

    Livewire::test(Index::class)->assertSet('receipts', '100000')->assertSee($client->name)
        ->set('presumptive', true)->assertSee('Total tax');
});

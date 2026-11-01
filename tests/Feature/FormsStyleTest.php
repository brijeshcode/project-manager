<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('forms and login use the shared field classes, not hard-coded field styles', function () {
    $admin = User::factory()->create()->assignRole('super_admin');

    $pages = [
        'clients create' => fn () => $this->actingAs($admin)->get(route('clients.create')),
        'payments' => fn () => $this->actingAs($admin)->get(route('payments.index')),
        'recurring' => fn () => $this->actingAs($admin)->get(route('billing.recurring')),
        'bank accounts' => fn () => $this->actingAs($admin)->get(route('billing.bank-accounts')),
        'invoice create' => fn () => $this->actingAs($admin)->get(route('invoices.create')),
    ];
    foreach ($pages as $name => $req) {
        $res = $req();
        $res->assertOk();
        $html = $res->getContent();
        expect($html)->toMatch('/class="(input|select)[ "]/', $name)->not->toContain('border-slate-300', $name);
    }

    auth()->logout();
    $res = $this->get(route('login'));
    $res->assertOk();
    expect($res->getContent())->toMatch('/class="input[ "]/')->not->toContain('border-slate-300');
});

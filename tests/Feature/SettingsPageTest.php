<?php

use App\Livewire\Settings\Index;
use App\Models\Client;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\User;
use App\Services\LocalCurrency;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function stUser(string $role): User
{
    $u = User::factory()->create(['name' => ucfirst($role).' Person']);
    $u->assignRole($role);

    return $u;
}

it('shows the page to the owner (no menu entry)', function () {
    $r = actingAs(stUser('super_admin'))->get(route('settings.index'));
    $r->assertOk()->assertSee('Local currency')->assertSee('Exchange rates')->assertDontSee('href="'.route('settings.index').'"', false);
    $r->assertSeeHtml('<option value="INR" selected>');
});

it('forbids developers and clients on route and component', function (string $role) {
    $u = stUser($role);
    $u->update(['client_id' => $role === 'client' ? \App\Models\Client::create(['name' => 'C', 'currency' => 'USD', 'billing_mode' => 'fixed'])->id : null]);
    actingAs($u)->get(route('settings.index'))->assertForbidden();
    Livewire::actingAs($u)->test(Index::class)->assertForbidden();
    actingAs($u)->get($role === 'client' ? route('portal.dashboard') : '/dashboard')->assertOk()->assertDontSee(route('settings.index'));
})->with(['developer', 'client']);

it('saves the local currency with a toast', function () {
    Livewire::actingAs(stUser('super_admin'))->test(Index::class)
        ->set('localCurrency', 'USD')->call('saveLocalCurrency')
        ->assertHasNoErrors()->assertDispatched('toast', type: 'success');
    expect(LocalCurrency::code())->toBe('USD');
});

it('rejects a malformed local currency', function () {
    Livewire::actingAs(stUser('super_admin'))->test(Index::class)
        ->set('localCurrency', 'US')->call('saveLocalCurrency')->assertHasErrors('localCurrency');
    expect(Settings::get('local_currency', null))->toBeNull();
});

it('refuses to change a locked local currency', function () {
    $c = Client::create(['name' => 'C', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'paid_on' => now(), 'type' => 'project']);
    $p->forceFill(['exchange_rate' => 83, 'local_amount_cents' => 415000, 'local_currency' => 'INR'])->save();

    actingAs(stUser('super_admin'))->get(route('settings.index'))
        ->assertOk()->assertSee('Locked — money has already been recorded in INR');

    Livewire::actingAs(stUser('super_admin'))->test(Index::class)
        ->set('localCurrency', 'USD')->call('saveLocalCurrency')->assertDispatched('toast', type: 'error');
    expect(LocalCurrency::code())->toBe('INR');
});

it('adds a rate shown with the owner name, and keeps history', function () {
    $owner = stUser('super_admin');
    $t = Livewire::actingAs($owner)->test(Index::class)
        ->set('currency', 'usd')->set('rate', '83.5')->call('addRate')
        ->assertHasNoErrors()->assertDispatched('toast', type: 'success')
        ->assertSee('83.500000')->assertSee('Super_admin Person');
    expect(ExchangeRate::count())->toBe(1);

    $t->set('currency', 'USD')->set('rate', '84')->call('addRate')->assertHasNoErrors();
    expect(ExchangeRate::where('currency', 'USD')->count())->toBe(2)
        ->and(ExchangeRate::where('currency', 'USD')->where('is_active', true)->count())->toBe(1);
    $t->assertSee('83.500000')->assertSee('84.000000')->assertSee('Active');
});

it('rejects invalid rates', function (string $rate) {
    Livewire::actingAs(stUser('super_admin'))->test(Index::class)
        ->set('currency', 'USD')->set('rate', $rate)->call('addRate')->assertHasErrors('rate');
    expect(ExchangeRate::count())->toBe(0);
})->with(['0', '-1', 'abc', '1.1234567', "83\n", '']);

it('rejects a bad currency code and the local currency itself', function (string $code) {
    Livewire::actingAs(stUser('super_admin'))->test(Index::class)
        ->set('currency', $code)->set('rate', '83')->call('addRate')->assertHasErrors('currency');
    expect(ExchangeRate::count())->toBe(0);
})->with(['US', 'INR', 'inr', '']);

it('authorises every action', function (string $role, string $action) {
    $t = Livewire::actingAs(stUser('super_admin'))->test(Index::class);
    $this->actingAs(stUser($role));
    $t->set('currency', 'USD')->set('rate', '83')->call($action)->assertForbidden();
    expect(ExchangeRate::count())->toBe(0)->and(Settings::get('local_currency', null))->toBeNull();
})->with(['developer', 'client'])->with(['addRate', 'saveLocalCurrency']);

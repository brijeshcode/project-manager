<?php

use App\Livewire\Payments\Index;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    (new ExchangeRates)->setRate('USD', '83.5', $this->owner);
});

function lcClient(string $cur = 'USD'): Client
{
    return Client::create(['name' => 'Acme '.$cur, 'currency' => $cur, 'billing_mode' => 'fixed']);
}

function lcPage(Client $c, string $amount = '100.00', ?string $rate = null)
{
    $t = Livewire::actingAs(test()->owner)->test(Index::class)->assertOk()->set('client_id', $c->id)->set('amount', $amount);

    return $rate === null ? $t : $t->set('exchange_rate', $rate);
}

it('prefills the active rate and shows the header hint and preview', function () {
    $c = lcClient();
    lcPage($c)->assertSet('exchange_rate', '83.5')->assertSee('Local currency: INR')->assertSee('≈ ₹8,350.00');
});

it('stores rate and local amount and leaves the active rate alone', function () {
    $c = lcClient();
    lcPage($c)->call('save')->assertHasNoErrors();
    $p = Payment::firstOrFail();
    expect($p->exchange_rate)->toBe('83.500000')->and($p->local_amount_cents)->toBe(835000)->and($p->local_currency)->toBe('INR')
        ->and(app(ExchangeRates::class)->active('USD'))->toBe('83.500000');
});

it('uses a typed rate for that payment only', function () {
    $c = lcClient();
    lcPage($c, '100.00', '90')->call('save')->assertHasNoErrors();
    expect(Payment::firstOrFail()->local_amount_cents)->toBe(900000)
        ->and(app(ExchangeRates::class)->active('USD'))->toBe('83.500000');
});

it('uses rate 1 and no rate field for a client in the local currency', function () {
    $c = lcClient('INR');
    lcPage($c)->assertSet('exchange_rate', '1')->assertDontSee('≈')->assertDontSee('for="exchange_rate"', false)
        ->call('save')->assertHasNoErrors();
    $p = Payment::firstOrFail();
    expect($p->local_amount_cents)->toBe(10000)->and($p->local_currency)->toBe('INR')->and($p->hasLocal())->toBeTrue();
});

it('stores the exact BIGINT product at the maximum size', function () {
    $c = lcClient();
    lcPage($c, '9999999.99', '999999.999999')->call('save')->assertHasNoErrors();
    // 999999999 * 999999.999999 = 999999999e6 - 999.999999 = 999999998999000.000001 -> half-up
    expect((string) Payment::firstOrFail()->local_amount_cents)->toBe('999999998999000');
});

it('rejects invalid rates', function (string $rate) {
    $c = lcClient();
    lcPage($c, '100.00', $rate)->call('save')->assertHasErrors('exchange_rate');
    expect(Payment::count())->toBe(0);
})->with(['0', '-5', '1e5', '.5', '1.1234567', '', '1000000', "83\n"]);

it('requires a rate when none is active or typed', function () {
    $c = lcClient('EUR');
    lcPage($c)->assertSet('exchange_rate', '')->call('save')->assertHasErrors('exchange_rate');
    expect(Payment::count())->toBe(0);
});

it('lists both numbers for a converted row and one for a same-currency row', function () {
    $c = lcClient();
    lcPage($c)->call('save');
    $i = lcClient('INR');
    lcPage($i, '50.00')->call('save');
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()
        ->assertSee('$100.00')->assertSee('₹8,350.00')->assertSee('@ 83.5')->assertSee('₹50.00');
    expect(Payment::where('client_id', $i->id)->first()->local_amount_cents)->toBe(5000);
});

it('sets a rate on a legacy row once, owner only', function () {
    $c = lcClient();
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 10000, 'paid_on' => today(), 'type' => 'project']);
    $t = Livewire::actingAs($this->owner)->test(Index::class)->assertOk()->assertSee('Set rate');
    $t->set("rowRate.{$p->id}", '1e5')->call('setRate', $p->id)->assertHasErrors();
    expect($p->fresh()->hasLocal())->toBeFalse();
    $t->set("rowRate.{$p->id}", '80')->call('setRate', $p->id)->assertHasNoErrors();
    $p->refresh();
    expect($p->local_amount_cents)->toBe(800000)->and($p->exchange_rate)->toBe('80.000000')->and($p->local_currency)->toBe('INR');
    $t->set("rowRate.{$p->id}", '90')->call('setRate', $p->id);
    expect($p->fresh()->local_amount_cents)->toBe(800000);
    expect(app(ExchangeRates::class)->active('USD'))->toBe('83.500000');
});

it('forbids setRate for non-owners', function () {
    $c = lcClient();
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 10000, 'paid_on' => today(), 'type' => 'project']);
    $t = Livewire::actingAs($this->owner)->test(Index::class)->set("rowRate.{$p->id}", '80');
    $this->actingAs(User::factory()->create()->assignRole('project_manager'));
    $t->call('setRate', $p->id)->assertForbidden();
    expect($p->fresh()->hasLocal())->toBeFalse();
});

it('still deletes', function () {
    $c = lcClient();
    lcPage($c)->call('save');
    Livewire::actingAs($this->owner)->test(Index::class)->call('deletePayment', Payment::first()->id);
    expect(Payment::count())->toBe(0);
});

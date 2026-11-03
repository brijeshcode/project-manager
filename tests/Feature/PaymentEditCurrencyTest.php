<?php

use App\Livewire\Payments\Edit;
use App\Livewire\Payments\Index;
use App\Livewire\Payments\Show;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientAccount;
use App\Services\ExchangeRates;
use App\Support\Money;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $o = User::factory()->create()->assignRole('super_admin');
    (new ExchangeRates)->setRate('USD', '80', $o);
    (new ExchangeRates)->setRate('EUR', '100', $o);
});

function pcOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function pcClient(string $cur = 'USD'): Client
{
    return Client::create(['name' => 'Acme', 'currency' => $cur, 'billing_mode' => 'fixed']);
}

it('unconverts a local amount back to a currency', function () {
    expect(Money::unconvert(10000, '80'))->toBe(125)
        ->and(Money::unconvert(Money::convert(12345, '83.5'), '83.5'))->toBe(12345);
});

it('offers the local currency and every currency with an active rate', function () {
    $c = pcClient();
    Livewire::actingAs(pcOwner())->test(Index::class)->set('client_id', $c->id)
        ->assertSet('currency', 'USD')
        ->assertSeeInOrder(['EUR', 'INR', 'USD']);
});

it('records a payment in the client currency as before', function () {
    $c = pcClient();
    Livewire::actingAs(pcOwner())->test(Index::class)->set('client_id', $c->id)->set('amount', '100')->call('save')->assertHasNoErrors();
    $p = Payment::first();
    expect($p->amount_cents)->toBe(10000)->and($p->paid_amount_cents)->toBeNull()->and($p->currency)->toBe('USD')
        ->and($p->local_amount_cents)->toBe(800000);
});

it('records a payment in another currency: amount, currency, rate and local amount', function () {
    $c = pcClient('USD');
    // 50.00 EUR @ 100 = 5,000.00 INR
    Livewire::actingAs(pcOwner())->test(Index::class)->set('client_id', $c->id)->set('currency', 'EUR')
        ->assertSet('exchange_rate', '100')->set('amount', '50')->call('save')->assertHasNoErrors();
    $p = Payment::first();
    expect($p->currency)->toBe('EUR')->and($p->amount_cents)->toBe(5000)
        ->and((string) $p->exchange_rate)->toBe('100.000000')->and($p->local_amount_cents)->toBe(500000);
});

it('shows the client balance in the client currency and in local currency', function () {
    $c = pcClient('USD');
    // billed 100.00 USD @ 80 = 8,000 INR; paid 50.00 EUR @ 100 = 5,000 INR = 62.50 USD @ 80
    app(\App\Services\InvoiceService::class)->create($c, [\App\Models\BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 10000, 'amount_cents' => 10000])->id], [], 0.0, null, '80');
    Payment::create(['client_id' => $c->id, 'currency' => 'EUR', 'amount_cents' => 5000, 'exchange_rate' => '100', 'local_amount_cents' => 500000, 'local_currency' => 'INR', 'paid_on' => today(), 'type' => 'project']);
    $acct = app(ClientAccount::class);
    expect($acct->balanceCents($c))->toBe(-3750)->and($acct->localBalanceCents($c))->toBe(-300000);
});

it('rejects a currency that is not active in the system', function () {
    $c = pcClient();
    Livewire::actingAs(pcOwner())->test(Index::class)->set('client_id', $c->id)->set('currency', 'JPY')->set('amount', '10')->call('save')->assertHasErrors('currency');
    expect(Payment::count())->toBe(0);
});

it('edits every field, recalculating the local amount and moving the balance', function () {
    $c = pcClient();
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 10000, 'currency' => 'USD', 'exchange_rate' => '80', 'local_amount_cents' => 800000, 'local_currency' => 'INR', 'paid_on' => today(), 'type' => 'project']);
    $other = pcClient('EUR');

    Livewire::actingAs(pcOwner())->test(Edit::class, ['payment' => $p])
        ->assertSet('amount', '100.00')->assertSet('currency', 'USD')
        ->set('client_id', $other->id)->assertSet('currency', 'EUR')
        ->set('amount', '20')->set('method', 'wire')->set('notes', 'fixed')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('payments.show', $p));

    $p->refresh();
    expect($p->client_id)->toBe($other->id)->and($p->currency)->toBe('EUR')->and($p->amount_cents)->toBe(2000)->and($p->local_amount_cents)->toBe(200000)
        ->and($p->method)->toBe('wire')->and($p->notes)->toBe('fixed');
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(0)->and(app(ClientAccount::class)->balanceCents($other))->toBe(2000);
});

it('edit switches a payment to another currency', function () {
    $c = pcClient('USD');
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 10000, 'currency' => 'USD', 'exchange_rate' => '80', 'local_amount_cents' => 800000, 'local_currency' => 'INR', 'paid_on' => today(), 'type' => 'project']);
    Livewire::actingAs(pcOwner())->test(Edit::class, ['payment' => $p])->set('currency', 'EUR')->set('amount', '80')->call('save')->assertHasNoErrors();
    $p->refresh();
    expect($p->currency)->toBe('EUR')->and($p->amount_cents)->toBe(8000)->and($p->local_amount_cents)->toBe(800000)->and((string) $p->exchange_rate)->toBe('100.000000');
});

it('shows a payment page with amount, rate and local amount, and deletes from it', function () {
    $c = pcClient('USD');
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'currency' => 'EUR', 'exchange_rate' => '100', 'local_amount_cents' => 500000, 'local_currency' => 'INR', 'paid_on' => today(), 'type' => 'project']);
    $t = Livewire::actingAs(pcOwner())->test(Show::class, ['payment' => $p])->assertSee('€50.00')->assertSee('₹5,000.00')->assertSee('100');
    $t->call('delete')->assertRedirect(route('payments.index'));
    expect(Payment::count())->toBe(0);
});
it('shows payment stats in local currency', function () {
    $c = pcClient('USD');
    Payment::create(['client_id' => $c->id, 'amount_cents' => 10000, 'currency' => 'USD', 'exchange_rate' => '80', 'local_amount_cents' => 800000, 'local_currency' => 'INR', 'paid_on' => today(), 'type' => 'project']);
    Payment::create(['client_id' => $c->id, 'amount_cents' => 20000, 'currency' => 'USD', 'exchange_rate' => '80', 'local_amount_cents' => 1600000, 'local_currency' => 'INR', 'paid_on' => today(), 'type' => 'project']);
    Livewire::actingAs(pcOwner())->test(Index::class)->assertSee('Total received')->assertSee('₹24,000.00')->assertSee('Average payment')->assertSee('₹12,000.00');
});

it('keeps edit and view owner only', function () {
    $c = pcClient();
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 100, 'currency' => 'USD', 'paid_on' => today(), 'type' => 'project']);
    $dev = User::factory()->create()->assignRole('developer');
    Livewire::actingAs($dev)->test(Edit::class, ['payment' => $p])->assertForbidden();
    Livewire::actingAs($dev)->test(Show::class, ['payment' => $p])->assertForbidden();
});

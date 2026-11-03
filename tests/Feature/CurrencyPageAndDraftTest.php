<?php

use App\Livewire\Clients\Show as ClientShow;
use App\Livewire\Currencies\Index;
use App\Livewire\Invoices\Create;
use App\Livewire\Invoices\Index as InvoiceIndex;
use App\Livewire\Invoices\Show;
use App\Livewire\Payments\Index as PayIndex;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientAccount;
use App\Services\ExchangeRates;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    (new ExchangeRates)->setRate('USD', '80', $this->owner);
});

function cdClient(string $cur = 'USD'): Client
{
    return Client::create(['name' => 'Acme '.$cur, 'currency' => $cur, 'billing_mode' => 'fixed', 'contact_email' => 'a@acme.test']);
}

function cdLine(Client $c, int $cents = 10000): BillableLine
{
    return BillableLine::create(['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

// ---- currency page

it('lists currencies, adds one, renames it and sets a rate', function () {
    $c = Livewire::actingAs($this->owner)->test(Index::class)->assertSee('USD')->assertSee('INR')->assertSee('local');
    $c->set('code', 'gbp')->set('name', 'Pound Sterling')->call('add')->assertHasNoErrors()->assertSee('GBP');
    $gbp = Currency::where('code', 'GBP')->firstOrFail();
    expect($gbp->is_active)->toBeTrue();

    $c->set("names.{$gbp->id}", 'British Pound')->call('rename', $gbp->id)->assertHasNoErrors();
    expect($gbp->fresh()->name)->toBe('British Pound');

    $c->set("rate.{$gbp->id}", '105.5')->call('setRate', $gbp->id)->assertHasNoErrors();
    expect(app(ExchangeRates::class)->active('GBP'))->toBe('105.500000');
});

it('rejects a bad or duplicate code', function () {
    Livewire::actingAs($this->owner)->test(Index::class)->set('code', 'US$')->set('name', 'x')->call('add')->assertHasErrors('code');
    Livewire::actingAs($this->owner)->test(Index::class)->set('code', 'USD')->set('name', 'x')->call('add')->assertHasErrors('code');
});

it('deactivating a currency removes it from the payment form, and the local currency stays active', function () {
    $c = cdClient('USD');
    $eur = Currency::forCode('EUR');
    Livewire::actingAs($this->owner)->test(PayIndex::class)->set('client_id', $c->id)->assertSeeHtml('<option value="EUR">EUR</option>');

    $page = Livewire::actingAs($this->owner)->test(Index::class)->call('toggle', $eur->id);
    expect($eur->fresh()->is_active)->toBeFalse();
    Livewire::actingAs($this->owner)->test(PayIndex::class)->set('client_id', $c->id)->assertDontSeeHtml('<option value="EUR">EUR</option>');

    $inr = Currency::forCode('INR');
    $page->call('toggle', $inr->id);
    expect($inr->fresh()->is_active)->toBeTrue();
});

it('only deletes a currency that nothing uses', function () {
    cdClient('USD');
    $usd = Currency::where('code', 'USD')->first();
    $unused = Currency::forCode('JPY');
    $page = Livewire::actingAs($this->owner)->test(Index::class);
    $page->call('delete', $usd->id);
    expect(Currency::where('code', 'USD')->exists())->toBeTrue();
    $page->call('delete', $unused->id);
    expect(Currency::where('code', 'JPY')->exists())->toBeFalse();
});

it('is owner only', function () {
    $dev = User::factory()->create()->assignRole('developer');
    Livewire::actingAs($dev)->test(Index::class)->assertForbidden();
});

// ---- drafts (not sent) and payments

it('a payment has no invoice', function () {
    $c = cdClient('USD');
    Livewire::actingAs($this->owner)->test(PayIndex::class)->set('client_id', $c->id)->set('amount', '10')->call('save')->assertHasNoErrors();
    expect(Payment::first()->getAttributes())->not->toHaveKey('invoice_id');
});

it('saves an invoice as a draft: no email, no balance change, shown as a future invoice', function () {
    $c = cdClient('USD');
    $line = cdLine($c, 25000);
    Livewire::withQueryParams(['client' => $c->id])->actingAs($this->owner)->test(Create::class)->call('saveDraft')->assertHasNoErrors()->assertRedirect();
    $inv = Invoice::firstOrFail();
    expect($inv->status)->toBe('draft')->and($inv->emailed_at)->toBeNull()
        ->and(app(ClientAccount::class)->balanceCents($c))->toBe(0);

    Livewire::actingAs($this->owner)->test(ClientShow::class, ['client' => $c])
        ->assertSee('Future invoices')->assertSee($inv->number)->assertSee('$250.00');
});

it('sending a draft makes it count against the client balance', function () {
    $c = cdClient('USD');
    $inv = app(InvoiceService::class)->create($c, [cdLine($c, 25000)->id], [], 0.0, null, null, null, true);
    Livewire::actingAs($this->owner)->test(Show::class, ['invoice' => $inv])->assertSee('Send invoice')->call('sendNow');
    expect($inv->fresh()->status)->toBe('sent')->and(app(ClientAccount::class)->balanceCents($c))->toBe(-25000);
});

it('invoice stats keep drafts out of the totals and show them as an estimate', function () {
    $c = cdClient('USD');
    app(InvoiceService::class)->create($c, [cdLine($c, 10000)->id]);                                 // sent: 100 USD = 8,000 INR
    app(InvoiceService::class)->create($c, [cdLine($c, 50000)->id], [], 0.0, null, null, null, true); // draft: 500 USD = 40,000 INR
    Livewire::actingAs($this->owner)->test(InvoiceIndex::class)
        ->assertSee('Future invoices')->assertSee('₹40,000.00')->assertSee('₹8,000.00')->assertSee('not sent');
});

it('a positive client balance is an advance, a negative one is owed', function () {
    $c = cdClient('USD');
    app(InvoiceService::class)->create($c, [cdLine($c, 10000)->id]);
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(-10000); // owes
    Payment::create(['client_id' => $c->id, 'amount_cents' => 25000, 'currency' => 'USD', 'paid_on' => today(), 'type' => 'project', 'exchange_rate' => '80', 'local_amount_cents' => 2000000, 'local_currency' => 'INR']);
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(15000);   // advance
});

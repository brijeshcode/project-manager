<?php

use App\Livewire\Invoices\Create;
use App\Models\BillableLine;
use App\Models\Client;
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
    (new ExchangeRates)->setRate('USD', '83.5', $this->owner);
    $this->c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $this->acct = app(ClientAccount::class);
});

function cbLine(Client $c, int $cents): BillableLine
{
    return BillableLine::create(['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

function cbPay(Client $c, int $cents, string $cur = 'USD', string $rate = '83.5'): Payment
{
    return Payment::create(['client_id' => $c->id, 'amount_cents' => $cents, 'currency' => $cur, 'paid_on' => today(), 'type' => 'project',
        'exchange_rate' => $rate, 'local_amount_cents' => \App\Support\Money::convert($cents, $rate), 'local_currency' => 'INR']);
}

it('a sent invoice from the builder adds its total (with tax) to what the client owes; a draft does not', function () {
    cbLine($this->c, 10000);
    Livewire::withQueryParams(['client' => $this->c->id])->actingAs($this->owner)->test(Create::class)
        ->call('addAllWork')->set('taxPercent', '10')->call('saveDraft')->assertHasNoErrors();
    expect($this->acct->balanceCents($this->c))->toBe(0)->and($this->acct->localBalanceCents($this->c))->toBe(0);

    cbLine($this->c, 20000);
    Livewire::withQueryParams(['client' => $this->c->id])->actingAs($this->owner)->test(Create::class)
        ->call('addAllWork')->set('taxPercent', '10')->call('save')->assertHasNoErrors();
    expect($this->acct->balanceCents($this->c))->toBe(-22000)
        ->and($this->acct->localBalanceCents($this->c))->toBe(-1837000); // 220.00 x 83.5
});

it('sending a draft counts it, setting it back or voiding takes it out', function () {
    $svc = app(InvoiceService::class);
    $inv = $svc->create($this->c, [cbLine($this->c, 10000)->id], draft: true);
    $svc->markSent($inv);
    expect($this->acct->balanceCents($this->c))->toBe(-10000);
    $svc->setStatus($inv, 'draft');
    expect($this->acct->balanceCents($this->c))->toBe(0);
    $svc->markSent($inv);
    $svc->void($inv);
    expect($this->acct->balanceCents($this->c))->toBe(0);
});

it('a payment settles the balance and an overpayment becomes an advance', function () {
    app(InvoiceService::class)->create($this->c, [cbLine($this->c, 10000)->id]);
    cbPay($this->c, 4000);
    expect($this->acct->balanceCents($this->c))->toBe(-6000);
    cbPay($this->c, 6000);
    expect($this->acct->balanceCents($this->c))->toBe(0);
    cbPay($this->c, 2500);
    expect($this->acct->balanceCents($this->c))->toBe(2500)->and($this->acct->localBalanceCents($this->c))->toBe(208750);
});

it('keeps frozen rates: a later rate change moves neither the local balance nor same-currency amounts', function () {
    app(InvoiceService::class)->create($this->c, [cbLine($this->c, 10000)->id]);                 // 100 USD at 83.5
    app(InvoiceService::class)->create($this->c, [cbLine($this->c, 10000)->id], [], 0.0, null, null, 'INR'); // in INR: 100 USD -> 8,350 INR
    cbPay($this->c, 835000, 'INR', '1');
    (new ExchangeRates)->setRate('USD', '90', $this->owner);
    expect($this->acct->localBalanceCents($this->c))->toBe(-835000)       // 8,350 + 8,350 billed, 8,350 paid
        ->and($this->acct->totals([$this->c->id])[$this->c->id]['billed'])->toBe(10000 + 9278); // USD row as is; INR row via today's rate
});

<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use Illuminate\Support\Facades\DB;

function lcPay(string $cur): Payment
{
    $c = Client::create(['name' => 'C'.uniqid(), 'currency' => $cur, 'billing_mode' => 'fixed']);

    return Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'paid_on' => now(), 'type' => 'project']);
}

it('defaults to INR and stores a new code', function () {
    expect(LocalCurrency::code())->toBe('INR');
    LocalCurrency::set('usd', User::factory()->create());
    expect(LocalCurrency::code())->toBe('USD');
});

it('resolves rates', function () {
    expect(LocalCurrency::rateFor('INR'))->toBe('1')->and(LocalCurrency::rateFor('USD'))->toBeNull();
    (new ExchangeRates)->setRate('USD', '83', User::factory()->create());
    expect(LocalCurrency::rateFor('USD'))->toBe('83.000000');
});

it('locks once a payment is converted', function () {
    $p = lcPay('USD');
    expect(LocalCurrency::isLocked())->toBeFalse();
    $p->forceFill(['exchange_rate' => 83, 'local_amount_cents' => 415000, 'local_currency' => 'INR'])->save();
    expect(LocalCurrency::isLocked())->toBeTrue();
    expect(fn () => LocalCurrency::set('EUR', User::factory()->create()))->toThrow(DomainException::class);
    expect(LocalCurrency::code())->toBe('INR');
});

it('backfills legacy payments of local-currency clients only', function () {
    $inr = lcPay('INR');
    $usd = lcPay('USD');
    expect(LocalCurrency::backfill())->toBe(1);
    $inr->refresh();
    expect($inr->local_amount_cents)->toBe(5000)->and($inr->local_currency)->toBe('INR')->and((float) $inr->exchange_rate)->toBe(1.0);
    expect($usd->fresh()->local_amount_cents)->toBeNull();
});

it('migration data step backfills INR-client payments', function () {
    $inr = lcPay('INR');
    $usd = lcPay('USD');
    $m = require base_path('database/migrations/2026_11_03_000001_create_settings_and_exchange_rates.php');
    $m->down();
    $m->up();
    expect($inr->fresh()->local_amount_cents)->toBe(5000)->and($usd->fresh()->local_amount_cents)->toBeNull();
});

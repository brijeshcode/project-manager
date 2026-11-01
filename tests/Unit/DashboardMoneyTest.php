<?php

use App\Enums\PaymentType;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Support\DashboardMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

function dmPay(Client $c, int $cents, string $on): void
{
    Payment::create(['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => $on, 'type' => PaymentType::Project]);
}

it('groups received per client currency and excludes out of period', function () {
    $usd = Client::create(['name' => 'U', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inr = Client::create(['name' => 'I', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    dmPay($usd, 1000, '2026-10-10');
    dmPay($usd, 500, '2026-10-12');
    dmPay($inr, 7000, '2026-10-11');
    dmPay($usd, 99999, '2026-01-01');

    $r = DashboardMoney::forPeriod(now()->parse('2026-10-01'), now()->parse('2026-10-31'));

    expect($r['received'])->toEqual(['USD' => 1500, 'INR' => 7000]);
});

it('outstanding only counts negative balances, per currency', function () {
    $owes = Client::create(['name' => 'Owes', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $credit = Client::create(['name' => 'Credit', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $l = BillableLine::create(['client_id' => $owes->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 4000, 'amount_cents' => 4000]);
    app(InvoiceService::class)->create($owes, [$l->id])->update(['status' => 'sent']);
    dmPay($credit, 9000, now()->toDateString());

    $r = DashboardMoney::forPeriod(now()->subDay(), now());

    expect($r['outstanding'])->toBe(['USD' => 4000]);
});

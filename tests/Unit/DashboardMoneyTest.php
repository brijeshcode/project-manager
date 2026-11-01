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

// ---- revenue series (chart)
it('revenue series zero-fills days, splits currencies and keeps the last day inclusive', function () {
    $usd = Client::create(['name' => 'U', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inr = Client::create(['name' => 'I', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    dmPay($usd, 1000, '2026-10-01');
    dmPay($usd, 500, '2026-10-01');
    dmPay($usd, 250, '2026-10-03'); // last day of the range
    dmPay($inr, 7000, '2026-10-02');
    dmPay($usd, 99999, '2026-10-04'); // outside

    $s = DashboardMoney::revenueSeries(now()->parse('2026-10-01'), now()->parse('2026-10-03'), 'day');

    expect(array_keys($s))->toEqualCanonicalizing(['USD', 'INR'])
        ->and(array_column($s['USD'], 'cents'))->toBe([1500, 0, 250])
        ->and(array_column($s['INR'], 'cents'))->toBe([0, 7000, 0])
        ->and(array_column($s['USD'], 'label'))->toBe(['Oct 1', 'Oct 2', 'Oct 3']);
});

it('revenue series buckets by week (Monday start) and by month', function () {
    $usd = Client::create(['name' => 'U', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    dmPay($usd, 100, '2026-10-05'); // Monday
    dmPay($usd, 200, '2026-10-11'); // Sunday, same week
    dmPay($usd, 400, '2026-10-12'); // next Monday
    dmPay($usd, 800, '2026-09-20');

    $w = DashboardMoney::revenueSeries(now()->parse('2026-10-05'), now()->parse('2026-10-18'), 'week');
    expect(array_column($w['USD'], 'cents'))->toBe([300, 400]);

    $m = DashboardMoney::revenueSeries(now()->parse('2026-09-01'), now()->parse('2026-10-31'), 'month');
    expect(array_column($m['USD'], 'cents'))->toBe([800, 700])
        ->and(array_column($m['USD'], 'label'))->toBe(['Sep 2026', 'Oct 2026']);
});

it('revenue series is empty when there are no payments', function () {
    expect(DashboardMoney::revenueSeries(now()->subDays(6), now(), 'day'))->toBe([]);
});

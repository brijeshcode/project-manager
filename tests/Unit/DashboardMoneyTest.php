<?php

use App\Enums\PaymentType;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\InvoiceService;
use App\Support\DashboardMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

function dmClient(string $cur): Client
{
    return Client::create(['name' => 'C'.$cur, 'currency' => $cur, 'billing_mode' => 'fixed']);
}

/** Payment with stored local amount (INR unless $local is null / another code). */
function dmPay(Client $c, int $cents, string $on, ?int $localCents = null, string $localCur = 'INR'): void
{
    Payment::create(['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => $on, 'type' => PaymentType::Project,
        'local_amount_cents' => $localCents, 'local_currency' => $localCents === null ? null : $localCur,
        'exchange_rate' => $localCents === null ? null : '1']);
}

function dmRate(string $cur, string $rate): void
{
    (new ExchangeRates)->setRate($cur, $rate, User::factory()->create());
}

function dmOwe(Client $c, int $cents): void
{
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
    app(InvoiceService::class)->create($c, [$l->id])->update(['status' => 'sent']);
}

it('received is the plain sum of stored local amounts; NULL excluded and counted; other local currency excluded', function () {
    $usd = dmClient('USD');
    $eur = dmClient('EUR');
    dmPay($usd, 1000, '2026-10-10', 80000);
    dmPay($eur, 500, '2026-10-12', 45000);
    dmPay($usd, 700, '2026-10-13');                   // no rate yet
    dmPay($usd, 300, '2026-10-14', 999, 'GBP');       // recorded under another local currency
    dmPay($usd, 99999, '2026-01-01', 7777777);        // out of range

    $r = DashboardMoney::forPeriodLocal(now()->parse('2026-10-01'), now()->parse('2026-10-31'));

    expect($r['currency'])->toBe('INR')
        ->and($r['received'])->toBe(125000)
        ->and($r['payments_missing_rate'])->toBe(1);
});

it('this month uses the same stored-local rule', function () {
    $usd = dmClient('USD');
    dmPay($usd, 100, now()->toDateString(), 8000);
    dmPay($usd, 100, now()->toDateString());
    dmPay($usd, 100, now()->subMonths(2)->toDateString(), 5);

    expect(DashboardMoney::forPeriodLocal(now()->subDay(), now())['this_month'])->toBe(8000);
});

it('outstanding converts each owing client at the active rate, skips and counts a currency without one', function () {
    dmRate('USD', '80');
    $usd = dmClient('USD');
    $inr = dmClient('INR');
    $eur = dmClient('EUR');   // no rate
    $credit = dmClient('USD');
    dmOwe($usd, 4000);
    dmOwe($inr, 2500);
    dmOwe($eur, 9000);
    dmPay($credit, 9000, now()->toDateString(), 720000);

    $r = DashboardMoney::forPeriodLocal(now()->subDay(), now());

    expect($r['outstanding'])->toBe(320000 + 2500)
        ->and($r['outstanding_missing_rate'])->toBe(1);
});

it('revenue series zero-fills, sums local amounts and keeps the last day inclusive', function () {
    $usd = dmClient('USD');
    $eur = dmClient('EUR');
    dmPay($usd, 1, '2026-10-01', 1500);
    dmPay($eur, 1, '2026-10-01', 500);
    dmPay($usd, 1, '2026-10-03', 250);      // last day
    dmPay($usd, 1, '2026-10-02', 777);      // NULL local would be excluded:
    dmPay($usd, 1, '2026-10-02');
    dmPay($usd, 1, '2026-10-04', 99999);    // outside

    $s = DashboardMoney::revenueSeriesLocal(now()->parse('2026-10-01'), now()->parse('2026-10-03'), 'day');

    expect(array_column($s, 'cents'))->toBe([2000, 777, 250])
        ->and(array_column($s, 'label'))->toBe(['Oct 1', 'Oct 2', 'Oct 3']);
});

it('revenue series buckets by week (Monday start) and by month', function () {
    $usd = dmClient('USD');
    dmPay($usd, 1, '2026-10-05', 100); // Monday
    dmPay($usd, 1, '2026-10-11', 200); // Sunday, same week
    dmPay($usd, 1, '2026-10-12', 400); // next Monday
    dmPay($usd, 1, '2026-09-20', 800);

    $w = DashboardMoney::revenueSeriesLocal(now()->parse('2026-10-05'), now()->parse('2026-10-18'), 'week');
    expect(array_column($w, 'cents'))->toBe([300, 400]);

    $m = DashboardMoney::revenueSeriesLocal(now()->parse('2026-09-01'), now()->parse('2026-10-31'), 'month');
    expect(array_column($m, 'cents'))->toBe([800, 700])
        ->and(array_column($m, 'label'))->toBe(['Sep 2026', 'Oct 2026']);
});

it('revenue series is empty when there are no local payments', function () {
    dmPay(dmClient('USD'), 1, now()->toDateString()); // NULL local
    expect(DashboardMoney::revenueSeriesLocal(now()->subDays(6), now(), 'day'))->toBe([]);
});

it('received includes payments on both edge days of the range', function () {
    $usd = dmClient('USD');
    dmPay($usd, 1, '2026-10-01', 100);
    dmPay($usd, 1, '2026-10-31', 200);
    dmPay($usd, 1, '2026-09-30', 400);
    dmPay($usd, 1, '2026-11-01', 800);

    expect(DashboardMoney::forPeriodLocal(now()->parse('2026-10-01'), now()->parse('2026-10-31'))['received'])->toBe(300);
});

function dmInv(Client $c, array $o = []): \App\Models\Invoice
{
    static $n = 0;
    $n++;

    return \App\Models\Invoice::create($o + ['number' => 'T-'.$n, 'client_id' => $c->id, 'status' => 'sent', 'issued_on' => now()->toDateString(),
        'due_on' => null, 'currency' => $c->currency, 'subtotal_cents' => 10000, 'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => 10000]);
}

function dmPayInv(\App\Models\Invoice $i, int $cents): void
{
    Payment::create(['client_id' => $i->client_id, 'invoice_id' => $i->id, 'amount_cents' => $cents, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);
}

it('classifies invoice states for the period (inclusive edges, due today / null due are pending)', function () {
    $c = dmClient('USD');
    $from = now()->subDays(9)->startOfDay();
    dmInv($c, ['status' => 'void']);
    $paid = dmInv($c); dmPayInv($paid, 10000);
    $part = dmInv($c, ['due_on' => now()->addDay()->toDateString()]); dmPayInv($part, 4000);
    dmInv($c, ['due_on' => now()->subDay()->toDateString()]);                           // overdue
    $partOver = dmInv($c, ['due_on' => now()->subDays(3)->toDateString()]); dmPayInv($partOver, 9999); // overdue
    dmInv($c, ['due_on' => now()->toDateString()]);                                      // due today -> pending
    dmInv($c, ['issued_on' => $from->toDateString(), 'due_on' => now()->subDay()->toDateString()]); // edge in, overdue
    dmInv($c, ['issued_on' => $from->copy()->subDay()->toDateString()]);                // outside
    dmInv($c, ['issued_on' => now()->addDay()->toDateString()]);                         // outside

    expect(DashboardMoney::invoiceStatusCounts($from, now()))->toBe(['paid' => 1, 'pending' => 2, 'overdue' => 3, 'void' => 1]);
});

it('counts overdue all time and invoices issued this month', function () {
    $c = dmClient('USD');
    dmInv($c, ['issued_on' => now()->subMonths(5)->toDateString(), 'due_on' => now()->subMonths(4)->toDateString()]); // overdue, old
    $p = dmInv($c, ['due_on' => now()->subDay()->toDateString()]); dmPayInv($p, 10000);                              // paid -> not overdue
    dmInv($c, ['status' => 'void', 'due_on' => now()->subDay()->toDateString()]);                                    // void
    dmInv($c, ['due_on' => now()->toDateString()]);

    expect(DashboardMoney::overdueCount())->toBe(1)
        ->and(DashboardMoney::sentThisMonthCount())->toBe(2);
});

it('lists recent invoices latest first, limit 5, and upcoming dues soonest first', function () {
    $c = dmClient('USD');
    $ids = [];
    foreach (range(0, 6) as $d) {
        $ids[$d] = dmInv($c, ['issued_on' => now()->subDays($d)->toDateString(), 'due_on' => now()->addDays(10 - $d)->toDateString()])->id;
    }
    $sameDay = dmInv($c, ['issued_on' => now()->toDateString(), 'due_on' => now()->subDay()->toDateString()]);

    expect(DashboardMoney::recentInvoices()->pluck('id')->all())->toBe([$sameDay->id, $ids[0], $ids[1], $ids[2], $ids[3]]);

    $paid = dmInv($c, ['due_on' => now()->addDay()->toDateString()]); dmPayInv($paid, 10000);
    dmInv($c, ['status' => 'void', 'due_on' => now()->addDay()->toDateString()]);
    // due in 4,5,6,7,8,9,10 days; paid/void/overdue/null-due excluded; 5 soonest
    expect(DashboardMoney::upcomingDues()->pluck('id')->all())->toBe([$ids[6], $ids[5], $ids[4], $ids[3], $ids[2]]);
});

it('ranks top clients by local revenue, name tie-break, zero clients included, limit 5, with counts and outstanding', function () {
    dmRate('USD', '80');
    $mk = fn ($n, $cur = 'USD') => Client::create(['name' => $n, 'currency' => $cur, 'billing_mode' => 'fixed']);
    $zed = $mk('Zed'); $amy = $mk('Amy'); $bob = $mk('Bob'); $cat = $mk('Cat'); $dan = $mk('Dan'); $eve = $mk('Eve'); $inr = $mk('Inr', 'INR');
    dmPay($zed, 100, now()->toDateString(), 9000);
    dmPay($amy, 100, now()->toDateString(), 5000);
    dmPay($bob, 100, now()->toDateString(), 5000);
    dmPay($cat, 100, now()->toDateString(), 100);
    dmPay($dan, 100, now()->subDays(60)->toDateString(), 99999); // out of period
    dmPay($eve, 100, now()->toDateString(), null);               // NULL local excluded
    dmInv($zed, ['total_cents' => 2000]); dmInv($zed, ['status' => 'void']);
    dmInv($zed, ['issued_on' => now()->subDays(60)->toDateString()]);
    dmInv($inr, ['total_cents' => 700]);

    $rows = DashboardMoney::topClients(now()->subDays(29)->startOfDay(), now());
    expect(array_map(fn ($r) => $r['client']->name, $rows))->toBe(['Zed', 'Amy', 'Bob', 'Cat', 'Dan'])
        ->and(count($rows))->toBe(5)
        ->and($rows[0]['revenue'])->toBe(9000)->and($rows[0]['invoices'])->toBe(1)
        // Zed: received 100, billed 2000+10000(old)=12000 -> owes 11900 USD cents * 80
        ->and($rows[0]['outstanding'])->toBe(11900 * 80)
        ->and($rows[1]['outstanding'])->toBe(0)->and($rows[4]['revenue'])->toBe(0);

    $all = DashboardMoney::topClients(now()->subDays(29)->startOfDay(), now(), 20);
    $byName = collect($all)->keyBy(fn ($r) => $r['client']->name);
    expect($byName['Inr']['outstanding'])->toBe(700);
});

it('top client outstanding is null when the client currency has no rate', function () {
    $c = dmClient('EUR');
    dmInv($c, ['total_cents' => 5000]);
    expect(DashboardMoney::topClients(now()->subDays(29), now())[0]['outstanding'])->toBeNull();
});

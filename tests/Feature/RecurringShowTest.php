<?php

use App\Livewire\Billing\RecurringShow;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\ExchangeRates;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
});

function rsCharge(Client $c, array $o = []): RecurringCharge
{
    return RecurringCharge::create($o + ['client_id' => $c->id, 'description' => 'Hosting', 'amount_cents' => 5000,
        'frequency' => 'monthly', 'next_due_on' => '2019-04-01', 'active' => false]);
}

it('never freezes a fake 1:1 local amount when the currency has no rate', function () {
    $c = rsCharge($this->client);
    $l = BillableLine::create(['client_id' => $this->client->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 5000, 'amount_cents' => 5000]);
    expect([$c->exchange_rate, $c->local_amount_cents, $l->exchange_rate, $l->local_amount_cents])->toBe([null, null, null, null]);

    (new ExchangeRates)->setRate('USD', '84', $this->owner);
    $l->update(['description' => 'W2']);
    expect($l->fresh()->local_amount_cents)->toBe(420000);
});

it('shows the approx local amount at the current rate, never the raw amount', function () {
    $c = rsCharge($this->client);
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->assertDontSee('≈ ₹50.00')->assertDontSee('≈');

    (new ExchangeRates)->setRate('USD', '84', $this->owner);
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->assertSee('≈ ₹4,200.00');
});

it('shows the start and end dates', function () {
    $c = rsCharge($this->client, ['ends_on' => '2023-01-31']);
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->assertSee('Started Apr 1, 2019')->assertSee('Ended Jan 31, 2023');

    $open = rsCharge($this->client, ['description' => 'Domain', 'next_due_on' => today()->addMonth()->startOfMonth()]);
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $open])
        ->assertSee('Starts '.today()->addMonth()->startOfMonth()->toFormattedDateString())->assertSee('No end date');
});

it('records paused periods on resume and lists skipped and paused periods in the history', function () {
    $c = rsCharge($this->client, ['active' => true, 'next_due_on' => '2026-09-01']);
    $c->skip(1);
    $c->setActive(false, today());
    $c->setActive(true, \Carbon\Carbon::parse('2026-12-15'));
    expect($c->fresh()->paused_periods)->toBe(['2026-10', '2026-11', '2026-12']);

    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])
        ->assertSeeInOrder(['Dec 2026', 'Paused', 'Nov 2026', 'Paused', 'Oct 2026', 'Paused', 'Sep 2026', 'Skipped']);
    $this->get(route('billing.recurring'))->assertSee('1 skipped · 3 paused');
});

it('unskip moves next due back; a past period is billed now, a future one when due', function () {
    $c = rsCharge($this->client, ['active' => true, 'next_due_on' => today()->startOfMonth()->subMonth()->toDateString()]);
    $c->skip(4);
    [$last, $now, $next, $after] = array_map(fn ($m) => today()->startOfMonth()->addMonths($m)->format('Y-m'), [-1, 0, 1, 2]);
    $periods = fn () => BillableLine::where('recurring_charge_id', $c->id)->orderBy('period')->pluck('period')->all();

    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->call('unskip', $next)->assertOk();
    $c->refresh();
    expect($c->next_due_on->format('Y-m'))->toBe($next)->and($c->skipped_periods)->toBe([$last, $now, $after])->and($periods())->toBe([]);

    $c->unskip($last);
    expect($periods())->toBe([$last])->and($c->fresh()->next_due_on->format('Y-m'))->toBe($next);

    $c->generateDue(today()->startOfMonth()->addMonths(2));
    expect($periods())->toBe([$last, $next]);

    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->call('unskip', '1999-01')->assertStatus(422);
});

it('shows the price history from billed lines plus a new price not yet billed', function () {
    $c = rsCharge($this->client, ['active' => true, 'next_due_on' => '2019-04-01']);
    foreach (['2019-04' => 5000, '2019-05' => 5000, '2019-06' => 8000, '2019-07' => 8000] as $period => $cents) {
        BillableLine::create(['client_id' => $this->client->id, 'recurring_charge_id' => $c->id, 'period' => $period, 'description' => 'Hosting', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
    }
    $c->update(['amount_cents' => 12000, 'next_due_on' => '2019-08-01']);

    expect($c->priceHistory())->toBe([['2019-04', '2019-05', 5000], ['2019-06', '2019-07', 8000], ['2019-08', null, 12000]]);
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])
        ->assertSeeInOrder(['Price history', 'Aug 2019 – now', '$120.00', 'Jun 2019 – Jul 2019', '$80.00', 'Apr 2019 – May 2019', '$50.00']);
});

it('ignores one-month blips in the price history', function () {
    $c = rsCharge($this->client, ['active' => true, 'next_due_on' => '2019-04-01', 'amount_cents' => 8000]);
    foreach (['2019-04' => 5000, '2019-05' => 5000, '2019-06' => 1800, '2019-07' => 5000, '2019-08' => 8000, '2019-09' => 8000] as $period => $cents) {
        BillableLine::create(['client_id' => $this->client->id, 'recurring_charge_id' => $c->id, 'period' => $period, 'description' => 'Hosting', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
    }
    expect($c->priceHistory())->toBe([['2019-04', '2019-07', 5000], ['2019-08', null, 8000]]);
});

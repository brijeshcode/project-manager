<?php

use App\Livewire\Invoices\Create;
use App\Livewire\Invoices\Edit;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    (new \App\Services\ExchangeRates)->setRate('USD', '83.5', $this->owner);
    $this->travelTo('2026-11-15');
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $this->charge = RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Hosting', 'amount_cents' => 5000, 'frequency' => 'monthly', 'next_due_on' => '2026-11-01', 'active' => true]);
});

function irBuilder($test)
{
    return Livewire::withQueryParams(['client' => $test->client->id])->actingAs($test->owner)->test(Create::class);
}

it('puts the client-wide due recurring service on a new invoice, once', function () {
    $line = fn () => BillableLine::where('recurring_charge_id', $this->charge->id)->sole();
    irBuilder($this)->assertSet('groups', [['label' => 'Hosting (Nov 2026)', 'lines' => [(string) $line()->id], 'amount' => '50.00', 'period' => 'Nov 2026', 'currency' => 'USD']])
        ->assertSee('Recurring · Nov 2026');
    irBuilder($this);
    expect(BillableLine::where('recurring_charge_id', $this->charge->id)->count())->toBe(1)
        ->and($line()->period)->toBe('2026-11')->and($line()->project_id)->toBeNull();
});

it('skips paused services', function () {
    $this->charge->update(['active' => false]);
    irBuilder($this)->assertSet('groups', []);
    expect(BillableLine::count())->toBe(0);
});

it('saves an edited recurring amount on the line and the invoice', function () {
    irBuilder($this)->set('groups.0.amount', '65.50')->call('save')->assertHasNoErrors()->assertRedirect();
    $inv = Invoice::sole();
    $line = BillableLine::where('recurring_charge_id', $this->charge->id)->sole();
    expect($inv->total_cents)->toBe(6550)
        ->and([$line->invoice_id, $line->amount_cents, $line->unit_price_cents])->toBe([$inv->id, 6550, 6550])
        ->and($inv->lines_snapshot[0])->toMatchArray(['description' => 'Hosting (Nov 2026)', 'amount_cents' => 6550])
        ->and($this->charge->fresh()->amount_cents)->toBe(5000);
});

it('rejects an empty or zero recurring amount', function () {
    irBuilder($this)->set('groups.0.amount', '0')->call('save')->assertHasErrors('groups.0.amount');
    irBuilder($this)->set('groups.0.amount', '')->call('save')->assertHasErrors('groups.0.amount');
    expect(Invoice::count())->toBe(0);
});

it('a removed recurring item stays pending for a later invoice', function () {
    irBuilder($this)->call('removeGroup', 0)->assertSet('groups', [])->call('addCustom')
        ->set('custom.0.description', 'Fix')->set('custom.0.unit_price', '10')->call('save')->assertHasNoErrors();
    expect(BillableLine::where('recurring_charge_id', $this->charge->id)->sole()->invoice_id)->toBeNull();
    irBuilder($this)->assertCount('groups', 1); // offered again next time
});

it('edits a recurring item on an existing invoice', function () {
    $this->charge->generateDue(today());
    $line = BillableLine::where('recurring_charge_id', $this->charge->id)->sole();
    $inv = app(InvoiceService::class)->create($this->client, [$line->id], labels: [$line->id => 'Hosting (Nov 2026)']);
    Livewire::actingAs($this->owner)->test(Edit::class, ['invoice' => $inv])
        ->assertSet('groups.0.amount', '50.00')->assertSet('groups.0.label', 'Hosting (Nov 2026)')
        ->set('groups.0.amount', '40')->call('save')->assertHasNoErrors();
    expect($inv->fresh()->total_cents)->toBe(4000)->and($line->fresh()->amount_cents)->toBe(4000);
});

it('lists the subscriptions running in the invoice month without adding them', function () {
    RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Domain', 'amount_cents' => 1500, 'frequency' => 'yearly', 'next_due_on' => '2027-03-01', 'active' => true]);
    $old = RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Old app', 'amount_cents' => 8000, 'frequency' => 'monthly', 'next_due_on' => '2026-01-01', 'active' => false]);
    $old->forceFill(['client_state' => RecurringCharge::ENDED, 'starts_on' => '2022-01-01', 'ends_on' => '2025-12-31'])->save();
    $items = fn ($c) => collect($c->get('groups'))->pluck('label')->all();

    $c = irBuilder($this)->set('issuedOn', '2022-03-10')->assertSet('tab', 'subs')
        ->assertSee('Old app')->assertSee('Mar 2022')->assertDontSee('Domain');
    expect($items($c))->toBe(['Hosting (Nov 2026)']); // nothing added by the date change

    $c->set('issuedOn', '2026-03-05')->assertDontSee('Old app'); // ended before that month
    $c->set('issuedOn', '2022-03-10')->set('pickSubs', [(string) $old->id])->call('addPicked');
    expect($items($c))->toBe(['Hosting (Nov 2026)', 'Old app (Mar 2022)'])
        ->and(BillableLine::where('recurring_charge_id', $old->id)->exists())->toBeFalse(); // created only on save
    $c->assertSee('on this invoice');
    $c->call('save')->assertHasNoErrors();
    expect(BillableLine::where('recurring_charge_id', $old->id)->where('period', '2022-03')->whereNotNull('invoice_id')->exists())->toBeTrue();
});

it('stops billing after the end date and ends the service', function () {
    $this->charge->update(['ends_on' => '2026-12-15']);
    $this->travelTo('2027-02-10');
    $this->artisan('billing:generate-recurring')->assertSuccessful();
    expect(BillableLine::where('recurring_charge_id', $this->charge->id)->pluck('period')->sort()->values()->all())->toBe(['2026-11', '2026-12'])
        ->and($this->charge->fresh()->active)->toBeFalse()->and($this->charge->fresh()->client_state)->toBe(RecurringCharge::ENDED);
});

it('does not load pending subscription lines of a later month', function () {
    $this->charge->lineFor('2026-12');
    expect(collect(irBuilder($this)->get('groups'))->pluck('label')->all())->toBe(['Hosting (Nov 2026)']);
});

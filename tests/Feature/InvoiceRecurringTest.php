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

function irPicked($test)
{
    return irBuilder($test)->set('pickSubs', [(string) $test->charge->id])->call('addPicked');
}

it('adds nothing on load; a picked due service goes on the invoice, once', function () {
    $line = fn () => BillableLine::where('recurring_charge_id', $this->charge->id)->sole();
    irBuilder($this)->assertSet('groups', [])->set('pickSubs', [(string) $this->charge->id])->call('addPicked')->assertSet('groups', [['label' => 'Hosting (Nov 2026)', 'lines' => [(string) $line()->id], 'amount' => '50.00', 'period' => 'Nov 2026', 'currency' => 'USD']])
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
    irPicked($this)->set('groups.0.amount', '65.50')->call('save')->assertHasNoErrors()->assertRedirect();
    $inv = Invoice::sole();
    $line = BillableLine::where('recurring_charge_id', $this->charge->id)->sole();
    expect($inv->total_cents)->toBe(6550)
        ->and([$line->invoice_id, $line->amount_cents, $line->unit_price_cents])->toBe([$inv->id, 6550, 6550])
        ->and($inv->lines_snapshot[0])->toMatchArray(['description' => 'Hosting (Nov 2026)', 'amount_cents' => 6550])
        ->and($this->charge->fresh()->amount_cents)->toBe(5000);
});

it('rejects an empty or zero recurring amount', function () {
    irPicked($this)->set('groups.0.amount', '0')->call('save')->assertHasErrors('groups.0.amount');
    irPicked($this)->set('groups.0.amount', '')->call('save')->assertHasErrors('groups.0.amount');
    expect(Invoice::count())->toBe(0);
});

it('a removed recurring item goes back to the picker and stays pending', function () {
    irPicked($this)->call('removeGroup', 0)->assertSet('groups', [])->assertCount('parkedGroups', 0)->assertDontSee('On this invoice')->call('addCustom')
        ->set('custom.0.description', 'Fix')->set('custom.0.unit_price', '10')->call('save')->assertHasNoErrors();
    expect(BillableLine::where('recurring_charge_id', $this->charge->id)->sole()->invoice_id)->toBeNull();
    irPicked($this)->assertCount('groups', 1); // offered again next time
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
    expect($items($c))->toBe([]); // nothing added by the date change

    $c->set('issuedOn', '2026-03-05')->assertDontSee('Old app'); // ended before that month
    $c->set('issuedOn', '2022-03-10')->set('pickSubs', [(string) $old->id])->call('addPicked');
    expect($items($c))->toBe(['Old app (Mar 2022)'])
        ->and(BillableLine::where('recurring_charge_id', $old->id)->exists())->toBeFalse(); // created only on save
    $c->assertSee('On this invoice');
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

it('refresh reloads the picker and keeps invoice items', function () {
    $c = irPicked($this)->call('addCustom')->set('custom.0.description', 'Fix')->set('pickSubs', ['1']);
    RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Backups', 'amount_cents' => 900, 'frequency' => 'monthly', 'next_due_on' => '2026-11-01', 'active' => true]);
    $c->call('refreshWork')->assertSet('pickSubs', [])->assertCount('groups', 1)->assertCount('custom', 1)->assertSee('Backups');
});

it('marks a subscription month already billed with its invoice', function () {
    $line = $this->charge->lineFor('2026-11');
    $inv = app(InvoiceService::class)->create($this->client, [$line->id]);
    irBuilder($this)->set('tab', 'subs')->assertSee('Already billed · '.$inv->number)->assertSee(route('invoices.show', $inv));
});

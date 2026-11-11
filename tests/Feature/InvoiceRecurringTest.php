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

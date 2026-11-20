<?php

use App\Livewire\Invoices\Edit;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientAccount;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    (new \App\Services\ExchangeRates)->setRate('USD', '83.5', User::factory()->create()->assignRole('super_admin'));
});

function ieOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function ieClient(): Client
{
    return Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed', 'contact_email' => 'a@acme.test']);
}

function ieLine(Client $c, int $cents, string $desc = 'Work'): BillableLine
{
    return BillableLine::create(['client_id' => $c->id, 'description' => $desc, 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

function ieInvoice(Client $c, array $lineIds, array $custom = [], float $tax = 0): Invoice
{
    return app(InvoiceService::class)->create($c, $lineIds, $custom, $tax, null, '83.5');
}

it('edits header, drops a line, adds a pending line and a custom line, and recomputes totals', function () {
    $c = ieClient();
    $a = ieLine($c, 10000, 'Alpha');
    $b = ieLine($c, 20000, 'Beta');
    $inv = ieInvoice($c, [$a->id]);
    $number = $inv->number;

    $svc = app(InvoiceService::class);
    $svc->update($inv, [$b->id], [['description' => 'Extra', 'quantity' => '2', 'unit_price' => '50.00']], [
        'tax_percent' => 10, 'issued_on' => '2026-10-01', 'due_on' => '2026-10-31', 'terms' => 'net-30', 'bank_account_id' => null, 'exchange_rate' => '90',
    ]);

    $inv->refresh();
    expect($inv->number)->toBe($number)
        ->and($inv->subtotal_cents)->toBe(30000)
        ->and($inv->tax_cents)->toBe(3000)
        ->and($inv->total_cents)->toBe(33000)
        ->and($inv->issued_on->toDateString())->toBe('2026-10-01')
        ->and($inv->due_on->toDateString())->toBe('2026-10-31')
        ->and((string) $inv->exchange_rate)->toBe('90.000000')
        ->and(count($inv->lines_snapshot))->toBe(2);
    expect($a->fresh()->invoice_id)->toBeNull();      // dropped line is pending again
    expect($b->fresh()->invoice_id)->toBe($inv->id);  // pending line claimed
});

it('rewrites a manual line in place instead of duplicating it', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [], [['description' => 'Setup', 'quantity' => '1', 'unit_price' => '100']]);
    $line = BillableLine::where('invoice_id', $inv->id)->first();
    $h = ['tax_percent' => 0, 'issued_on' => $inv->issued_on->toDateString(), 'due_on' => null, 'terms' => null, 'bank_account_id' => null, 'exchange_rate' => '83.5'];
    app(InvoiceService::class)->update($inv, [$line->id], [], $h, [$line->id => ['description' => 'Setup v2', 'quantity' => '2', 'unit_price' => '75']]);

    expect(BillableLine::where('invoice_id', $inv->id)->count())->toBe(1)
        ->and($line->fresh()->description)->toBe('Setup v2')
        ->and($inv->fresh()->total_cents)->toBe(15000)
        ->and($inv->fresh()->lines_snapshot[0]['description'])->toBe('Setup v2');
});

it('refuses to edit a cancelled invoice or leave it with no lines', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $svc = app(InvoiceService::class);
    $h = ['tax_percent' => 0, 'issued_on' => '2026-10-01', 'due_on' => null, 'terms' => null, 'bank_account_id' => null, 'exchange_rate' => '83.5'];

    expect(fn () => $svc->update($inv, [], [], $h))->toThrow(\Illuminate\Validation\ValidationException::class);

    $svc->void($inv);
    expect(fn () => $svc->update($inv->fresh(), [], [['description' => 'x', 'quantity' => '1', 'unit_price' => '1']], $h))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects another client\'s pending line on edit', function () {
    $c = ieClient();
    $other = Client::create(['name' => 'Other', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $foreign = ieLine($other, 500);
    $h = ['tax_percent' => 0, 'issued_on' => '2026-10-01', 'due_on' => null, 'terms' => null, 'bank_account_id' => null, 'exchange_rate' => '83.5'];

    expect(fn () => app(InvoiceService::class)->update($inv, [$foreign->id], [], $h))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($foreign->fresh()->invoice_id)->toBeNull();
});

it('deletes an invoice, frees its lines, keeps payments and updates the client balance', function () {
    $c = ieClient();
    $line = ieLine($c, 10000);
    $inv = ieInvoice($c, [$line->id]);
    $p = Payment::create(['client_id' => $c->id, 'invoice_id' => $inv->id, 'amount_cents' => 4000, 'paid_on' => today(), 'type' => 'project']);
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(-6000);

    app(InvoiceService::class)->delete($inv);

    expect(Invoice::count())->toBe(0)
        ->and($line->fresh()->invoice_id)->toBeNull()
        ->and($p->fresh()->invoice_id)->toBeNull()
        ->and(app(ClientAccount::class)->balanceCents($c))->toBe(4000);
});

it('edit page loads, saves and redirects; delete from the show page redirects to the list', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $owner = ieOwner();

    Livewire::actingAs($owner)->test(Edit::class, ['invoice' => $inv])
        ->assertSet('taxPercent', '0.00')
        ->set('taxPercent', '5')->set('forMonth', '2024-01')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('invoices.show', $inv));
    expect($inv->fresh()->tax_cents)->toBe(500)->and($inv->fresh()->for_month)->toBe('2024-01');
    Livewire::actingAs($owner)->test(Index::class)->assertSeeInOrder(['Billed for', $inv->number, 'Jan 2024']);
    Livewire::actingAs($owner)->test(Edit::class, ['invoice' => $inv->fresh()])->assertSet('forMonth', '2024-01')
        ->set('forMonth', '2024-13')->call('save')->assertHasErrors('forMonth');

    Livewire::actingAs($owner)->test(Show::class, ['invoice' => $inv])->call('delete')->assertRedirect(route('invoices.index'));
    expect(Invoice::count())->toBe(0);
});

it('shows stats that exclude cancelled invoices and are in local currency', function () {
    $c = ieClient();
    ieInvoice($c, [ieLine($c, 10000)->id]);          // 100.00 USD @ 83.5 = 8,350.00 INR
    $gone = ieInvoice($c, [ieLine($c, 90000)->id]);
    app(InvoiceService::class)->void($gone);

    Livewire::actingAs(ieOwner())->test(Index::class)
        ->assertSee('Total invoiced')->assertSee('8,350.00')->assertDontSee('83,850.00')->assertSee('Cancelled');
});

it('still shows invoice stats when an invoice has no rate', function () {
    $c = ieClient();
    ieInvoice($c, [ieLine($c, 10000)->id]);
    $noRate = ieInvoice($c, [ieLine($c, 5000)->id]);
    $noRate->forceFill(['exchange_rate' => null])->save();

    Livewire::actingAs(ieOwner())->test(Index::class)
        ->assertSee('150.00')->assertSee('≈ ₹8,350.00', false)->assertSee('1 without a rate')
        ->assertSee('Clients billed')->assertSee('This month');
});

it('is owner only', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $dev = User::factory()->create()->assignRole('developer');
    Livewire::actingAs($dev)->test(Edit::class, ['invoice' => $inv])->assertForbidden();
});

it('previews the live total on edit when an item is removed and tax changes', function () {
    $c = ieClient();
    $a = ieLine($c, 10000);
    $inv = ieInvoice($c, [$a->id, ieLine($c, 5000)->id]);
    $total = fn ($t) => preg_match('/data-test="invoice-total">([^<]+)</', $t->html(), $m) ? $m[1] : null;
    $lw = Livewire::actingAs(ieOwner())->test(Edit::class, ['invoice' => $inv]);
    expect($total($lw))->toBe('$150.00');
    $lw->call('removeEdit', $a->id)->assertCount('parkedGroups', 1);
    expect($total($lw))->toBe('$50.00');
    $lw->set('taxPercent', '10');
    expect($total($lw))->toBe('$55.00');
});

it('edits with the same picker: adds completed work as a labelled item, keeps grouped labels, and drops removed work back to billable', function () {
    $c = ieClient();
    $a = ieLine($c, 10000, 'Setup');
    $inv = ieInvoice($c, [$a->id]);
    $more = ieLine($c, 2500, 'Hosting');
    $t = Livewire::actingAs(ieOwner())->test(Edit::class, ['invoice' => $inv])->assertSet('tab', 'charges')->assertSee('Hosting')
        ->set('pickCharges', [(string) $more->id])->call('addPicked')->assertSet('groups', [['label' => 'Hosting', 'lines' => [(string) $more->id]]])
        ->set('groups.0.label', 'Hosting, 12 months')->call('removeEdit', $a->id)->call('forgetGroup', 0)
        ->call('save')->assertHasNoErrors()->assertRedirect(route('invoices.show', $inv));
    $inv->refresh();
    expect(array_column($inv->lines_snapshot, 'description'))->toBe(['Hosting, 12 months'])->and($inv->total_cents)->toBe(2500)
        ->and($a->fresh()->invoice_id)->toBeNull()->and($more->fresh()->invoice_label)->toBe('Hosting, 12 months');
    // reopening shows the labelled item again, not the raw line
    Livewire::actingAs(ieOwner())->test(Edit::class, ['invoice' => $inv])->assertSet('groups', [['label' => 'Hosting, 12 months', 'lines' => [(string) $more->id]]]);
});

it('changes the status from the invoice list', function () {
    $c = ieClient();
    $inv = ieInvoice($c, [ieLine($c, 10000)->id]);
    $t = Livewire::actingAs(ieOwner())->test(App\Livewire\Invoices\Index::class)->assertSeeHtml('data-test="row-status"');
    $t->call('setStatus', $inv->id, 'paid')->assertDispatched('toast', type: 'error');
    expect($inv->fresh()->status)->toBe('sent');
    $t->call('setStatus', $inv->id, 'draft');
    expect($inv->fresh()->status)->toBe('draft');
    $t->call('setStatus', $inv->id, 'void')->assertDispatched('toast', type: 'error');
    expect($inv->fresh()->status)->toBe('draft');
});

it('stats follow the status and client filters', function () {
    $c = ieClient();
    $other = Client::create(['name' => 'Beta', 'currency' => 'USD', 'billing_mode' => 'fixed', 'contact_email' => 'b@beta.test']);
    ieInvoice($c, [ieLine($c, 10000)->id]);          // 8,350.00 INR
    ieInvoice($other, [ieLine($other, 20000)->id]);  // 16,700.00 INR

    Livewire::actingAs(ieOwner())->test(Index::class)
        ->assertSee('25,050.00')
        ->set('clientFilter', (string) $c->id)->assertSee('8,350.00')->assertDontSee('25,050.00')
        ->set('clientFilter', '')->set('status', 'draft')->assertSee('0 invoices')->assertSee('No drafts waiting');
});

it('filters by issued date with month, year and financial year shortcuts', function () {
    $this->travelTo('2026-11-11');
    $c = ieClient();
    $now = ieInvoice($c, [ieLine($c, 10000)->id]);
    $may = ieInvoice($c, [ieLine($c, 20000)->id]);
    $may->update(['issued_on' => '2026-05-10']);
    $feb = ieInvoice($c, [ieLine($c, 30000)->id]);
    $feb->update(['issued_on' => '2026-02-10']);

    $t = Livewire::actingAs(ieOwner())->test(Index::class)
        ->call('setPeriod', 'month')->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-30')
        ->assertSee($now->number)->assertDontSee($may->number)->assertSee('1 invoice')
        ->call('setPeriod', 'fy')->assertSet('from', '2026-04-01')->assertSet('to', '2027-03-31')
        ->assertSee($may->number)->assertDontSee($feb->number)
        ->call('setPeriod', 'year')->assertSee($feb->number)->assertSee('3 invoices')
        ->set('from', '2026-05-01')->set('to', '2026-05-31')->assertSee($may->number)->assertDontSee($now->number)
        ->call('setPeriod', '')->assertSet('from', '')->assertSee($now->number);
});

it('year dropdown lists years back to the oldest invoice and filters to one', function () {
    $this->travelTo('2026-11-11');
    $c = ieClient();
    $now = ieInvoice($c, [ieLine($c, 10000)->id]);
    $old = ieInvoice($c, [ieLine($c, 20000)->id]);
    $old->update(['issued_on' => '2024-07-01']);

    Livewire::actingAs(ieOwner())->test(Index::class)
        ->assertViewHas('years', [2026, 2025, 2024])
        ->call('setYear', '2024')->assertSet('from', '2024-01-01')->assertSet('to', '2024-12-31')->assertViewHas('year', 2024)
        ->assertSee($old->number)->assertDontSee($now->number)->assertSeeInOrder(['Invoices sent', '1', 'Total invoiced'])
        ->assertSeeInOrder(['Invoice month', 'Jul 2024'])
        ->call('setYear', '')->assertSeeInOrder(['Invoices sent', '2', 'Total invoiced'])->assertSet('from', '')->assertViewHas('year', null)->assertSee($now->number)
        ->call('setYear', 'junk')->assertSet('from', '');

    $portal = User::factory()->create(['client_id' => $c->id])->assignRole('client');
    Livewire::actingAs($portal)->test(\App\Livewire\Portal\Invoices::class)
        ->assertSeeHtml('data-test="year-filter"')->call('setYear', '2024')->assertSee($old->number)->assertDontSee($now->number);
});

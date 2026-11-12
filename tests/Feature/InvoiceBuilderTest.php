<?php

use App\Livewire\Invoices\Create;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Models\BankAccount;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // local INR default: USD clients' invoices prefill this active rate
    (new \App\Services\ExchangeRates)->setRate('USD', '83.5', User::factory()->create()->assignRole('super_admin'));
});

function ibOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function ibClient(array $o = []): Client
{
    return Client::create($o + ['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed', 'tax_number' => 'TAX-99', 'billing_address' => '1 Main St', 'contact_email' => 'a@acme.test']);
}

function ibLine(Client $c, int $cents, string $desc = 'Work'): BillableLine
{
    return BillableLine::create(['client_id' => $c->id, 'description' => $desc, 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

function ibBank(): BankAccount
{
    return BankAccount::create(['label' => 'Main', 'account_name' => 'Great Tech', 'bank_name' => 'HDFC', 'account_number' => '50100012341447', 'ifsc_swift' => 'HDFC0001234']);
}

function ibCreate(Client $c)
{
    return Livewire::withQueryParams(['client' => $c->id])->actingAs(ibOwner())->test(Create::class)->call('addAllWork');
}

it('lists pending lines ready to add, and Add all puts them on the invoice', function () {
    $c = ibClient();
    $a = ibLine($c, 10000, 'Alpha');
    $b = ibLine($c, 20000, 'Beta');
    Livewire::withQueryParams(['client' => $c->id])->actingAs(ibOwner())->test(Create::class)->assertSee('Alpha')->assertSee('Beta')->assertSet('groups', [])
        ->call('addAllWork')->assertSet('groups', [['label' => 'Alpha', 'lines' => [(string) $a->id]], ['label' => 'Beta', 'lines' => [(string) $b->id]]]);
});

it('invoices ticked lines and leaves unticked pending', function () {
    $c = ibClient();
    $a = ibLine($c, 10000);
    $b = ibLine($c, 20000);
    ibCreate($c)->call('removeGroup', 1)->call('save')->assertHasNoErrors()->assertRedirect();
    $inv = Invoice::first();
    expect($inv->total_cents)->toBe(10000);
    expect($a->fresh()->invoice_id)->toBe($inv->id);
    expect($b->fresh()->invoice_id)->toBeNull();
});

it('adds custom lines with bank, tax and exchange rate and renders them', function () {
    $c = ibClient();
    ibLine($c, 100000, 'Build');
    $bank = ibBank();
    ibCreate($c)->set('showQuantity', true)->call('addCustom')->set('custom.0.description', 'Extra')->set('custom.0.quantity', '2')->set('custom.0.unit_price', '400')
        ->set('taxPercent', '10')->set('bankAccountId', (string) $bank->id)->set('exchangeRate', '83.5')->call('save')->assertHasNoErrors();
    $inv = Invoice::first();
    expect([$inv->subtotal_cents, $inv->total_cents])->toBe([180000, 198000]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])
        ->assertSee('$1,980.00')->assertSee('Extra')->assertSee('HDFC')->assertSee('50100012341447')
        ->assertSee('165,330.00')->assertSee('83.5'); // 1980.00 * 83.5 = 165330
});

it('removes a custom line before saving', function () {
    $c = ibClient();
    ibLine($c, 1000);
    ibCreate($c)->call('addCustom')->call('removeCustom', 0)->assertSet('custom', [])->call('save')->assertHasNoErrors();
    expect(BillableLine::where('invoice_id', '!=', null)->count())->toBe(1);
});

it('gives errors, not 500s, for tampered line and bank ids', function () {
    $c = ibClient();
    $other = ibClient(['name' => 'Other']);
    $foreign = ibLine($other, 500);
    ibLine($c, 1000);
    ibCreate($c)->set('groups', [['label' => 'X', 'lines' => [(string) $foreign->id]]])->call('save')->assertHasErrors('lines');
    ibCreate($c)->set('groups', [['label' => 'X', 'lines' => ['999999']]])->call('save')->assertHasErrors('lines');
    ibCreate($c)->set('bankAccountId', '99999')->call('save')->assertHasErrors('bankAccountId');
    expect(Invoice::count())->toBe(0);
});

it('rejects malformed numerics and creates nothing', function () {
    $c = ibClient();
    ibLine($c, 1000);
    foreach (['1e3', 'abc', '-1', '999999999', 'NaN', '1.234', '101'] as $bad) {
        ibCreate($c)->set('taxPercent', $bad)->call('save')->assertHasErrors('taxPercent');
    }
    foreach (['1e3', 'abc', '-1', '999999999', '0', '1.2345678'] as $bad) {
        ibCreate($c)->set('exchangeRate', $bad)->call('save')->assertHasErrors('exchangeRate');
        ibCreate($c)->set('showQuantity', true)->call('addCustom')->set('custom.0.description', 'X')->set('custom.0.quantity', $bad)->set('custom.0.unit_price', '5')->call('save')->assertHasErrors('custom.0.quantity');
        ibCreate($c)->call('addCustom')->set('custom.0.description', 'X')->set('custom.0.quantity', '1')->set('custom.0.unit_price', $bad)->call('save')->assertHasErrors('custom.0.unit_price');
    }
    expect(Invoice::count())->toBe(0);
});

it('is forbidden for non-owners on routes and actions', function () {
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000)->id]);
    foreach (['project_manager', 'developer', 'client'] as $role) {
        $u = User::factory()->create()->assignRole($role);
        $this->actingAs($u)->get(route('invoices.index'))->assertForbidden();
        $this->actingAs($u)->get(route('invoices.create'))->assertForbidden();
        $this->actingAs($u)->get(route('invoices.show', $inv))->assertForbidden();
    }
    $this->actingAs(ibOwner())->get(route('invoices.index'))->assertOk();
    $this->actingAs(ibOwner())->get(route('invoices.create'))->assertOk();
    $this->actingAs(ibOwner())->get(route('invoices.show', $inv))->assertOk();

    $line = ibLine($c, 500);
    $t = ibCreate($c);
    $s = Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv]);
    $this->actingAs(User::factory()->create()->assignRole('project_manager'));
    $t->call('save')->assertForbidden();
    $s->call('void')->assertForbidden();
    expect(Invoice::count())->toBe(1);
    expect($inv->fresh()->status)->toBe('sent');
    expect($line->fresh()->invoice_id)->toBeNull();
});

it('voids from Show and the list shows void', function () {
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000)->id]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->call('void')->assertSee('VOID');
    expect($inv->fresh()->status)->toBe('void');
    Livewire::actingAs(ibOwner())->test(Index::class)->assertSee($inv->number)->assertSeeHtml('badge badge-rose');
});

it('lists newest first and filters by client', function () {
    $a = ibClient();
    $b = ibClient(['name' => 'Bravo Ltd']);
    $svc = app(InvoiceService::class);
    $i1 = $svc->create($a, [ibLine($a, 1000)->id]);
    $i2 = $svc->create($b, [ibLine($b, 2000)->id]);
    Livewire::actingAs(ibOwner())->test(Index::class)->assertSeeInOrder([$i2->number, $i1->number])->assertSee('sent')
        ->set('clientFilter', (string) $a->id)->assertSee($i1->number)->assertDontSee($i2->number);
});

it('escapes descriptions in the invoice body', function () {
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000, '<script>alert(1)</script>')->id]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});

it('omits the bank block without a snapshot and the INR line without a rate', function () {
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000)->id]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->assertDontSee('Bank details')->assertDontSee('INR');
});

it('shows the Invoices nav only to owners', function () {
    $this->actingAs(ibOwner())->get(route('dashboard'))->assertSee('Invoices');
    $this->actingAs(User::factory()->create()->assignRole('project_manager'))->get(route('dashboard'))->assertDontSee('Invoices');
});

it('rejects an oversized custom line, oversized pending line and oversized subtotal', function () {
    $c = ibClient();
    ibCreate($c)->set('showQuantity', true)->call('addCustom')->set('custom.0.description', 'X')->set('custom.0.quantity', '999999')->set('custom.0.unit_price', '9999999')->call('save')->assertHasErrors('custom.0');
    $big = ibLine($c, 2_000_000_001);
    ibCreate($c)->call('save')->assertHasErrors('lines');
    $big->delete();
    ibLine($c, 2_000_000_000);
    foreach (range(1, 21) as $i) {
        ibLine($c, 2_000_000_000);
    }
    ibCreate($c)->call('save')->assertHasErrors('lines'); // 22 x 2e9 = 4.4e10 > 4e10
    expect(Invoice::count())->toBe(0);
});

it('saves and renders an invoice exactly at the bounds', function () {
    $c = ibClient();
    foreach (range(1, 20) as $i) {
        ibLine($c, 2_000_000_000);
    }
    ibCreate($c)->set('taxPercent', '100')->set('exchangeRate', '999999.999999')->call('save')->assertHasNoErrors();
    $inv = Invoice::first();
    expect($inv->total_cents)->toBe(80_000_000_000);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->assertOk()->assertSee('800,000,000.00');
});

it('rejects exchange rate 1000000 and 51 custom lines', function () {
    $c = ibClient();
    ibLine($c, 1000);
    ibCreate($c)->set('exchangeRate', '1000000')->call('save')->assertHasErrors('exchangeRate');
    $t = ibCreate($c);
    foreach (range(1, 51) as $i) {
        $t->call('addCustom');
    }
    $t->call('save')->assertHasErrors('custom');
    expect(Invoice::count())->toBe(0);
});

it('voiding twice is a no-op and flashes status', function () {
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000)->id]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->call('void')->assertDispatched('toast', type: 'success', message: 'Invoice voided.')->call('void')->assertHasNoErrors();
    expect($inv->fresh()->status)->toBe('void');
});

it('choose requires a valid client and non-owners cannot call builder actions', function () {
    $t = Livewire::actingAs(ibOwner())->test(Create::class);
    $t->set('pick', '')->call('choose')->assertHasErrors('pick');
    $t->set('pick', '9999')->call('choose')->assertHasErrors('pick');
    $this->actingAs(User::factory()->create()->assignRole('project_manager'));
    $t->call('choose')->assertForbidden();
    foreach ([['addCustom'], ['removeCustom', 0]] as $call) {
        $this->actingAs(ibOwner());
        $u = Livewire::test(Create::class);
        $this->actingAs(User::factory()->create()->assignRole('developer'));
        $u->call(...$call)->assertForbidden();
    }
});

it('resend dispatches a success toast, and an error toast when it cannot send', function () {
    Mail::fake();
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000)->id]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])
        ->call('resend')->assertDispatched('toast', type: 'success', message: 'Invoice email queued.');

    $c2 = ibClient(['name' => 'NoMail', 'contact_email' => null]);
    $inv2 = app(InvoiceService::class)->create($c2, [ibLine($c2, 1000)->id]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv2])
        ->call('resend')->assertDispatched('toast', type: 'error');
});

it('previews the live total from ticked lines, custom lines and tax', function () {
    $c = ibClient();
    $a = ibLine($c, 10000);
    ibLine($c, 5000);
    $total = fn ($t) => preg_match('/data-test="invoice-total">([^<]+)</', $t->html(), $m) ? $m[1] : null;
    $lw = Livewire::withQueryParams(['client' => $c->id])->actingAs(ibOwner())->test(Create::class)->call('addAllWork');
    expect($total($lw))->toBe('$150.00');
    $lw->call('removeGroup', 1)->set('showQuantity', true)->call('addCustom')->set('custom.0.unit_price', '20')->set('custom.0.quantity', '1.5')->set('taxPercent', '10');
    expect($total($lw))->toBe('$143.00');
    $lw->set('custom.0.unit_price', 'abc');
    expect($total($lw))->toBe('$110.00');
});

it('adds or removes bank details from the show page', function () {
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000)->id]);
    $bank = ibBank();
    $s = Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->assertDontSee('Bank details')
        ->set('bankAccountId', (string) $bank->id)->assertSee('Bank details')->assertSee('50100012341447');
    expect($inv->fresh()->bank_snapshot['account_number'])->toBe('50100012341447');
    $s->set('bankAccountId', '')->assertDontSee('Bank details');
    expect($inv->fresh()->bank_snapshot)->toBeNull();
    $s->set('bankAccountId', '999999');
    expect($inv->fresh()->bank_account_id)->toBeNull();
});

it('filters the list by not sent, sent and cancelled only', function () {
    $svc = app(InvoiceService::class);
    $c = ibClient();
    $sent = $svc->create($c, [ibLine($c, 1000)->id]);
    $draft = $svc->create($c, [ibLine($c, 2000)->id], [], 0, null, '83.5', null, true);
    $void = $svc->create($c, [ibLine($c, 3000)->id]);
    $svc->void($void);

    $t = Livewire::actingAs(ibOwner())->test(Index::class)->assertDontSee('Awaiting payment')->assertDontSee('Overdue');
    $t->set('status', 'draft')->assertSee($draft->number)->assertDontSee($sent->number)->assertDontSee($void->number);
    $t->set('status', 'sent')->assertSee($sent->number)->assertDontSee($draft->number)->assertDontSee($void->number);
    $t->set('status', 'void')->assertSee($void->number)->assertDontSee($sent->number);
});

it('switches client on the same page and loads that client\'s work', function () {
    $a = ibClient();
    $b = ibClient(['name' => 'Bravo Ltd', 'currency' => 'INR']);
    $la = ibLine($a, 1000, 'Alpha work');
    $lb = ibLine($b, 2000, 'Bravo work');
    $t = Livewire::actingAs(ibOwner())->test(Create::class)->assertSee('Select a client')->assertDontSee('Alpha work');
    $t->set('pick', (string) $a->id)->assertSee('Alpha work')->assertSet('groups', [])->call('addAllWork')->assertCount('groups', 1)->assertSet('currency', 'USD');
    $t->call('addCustom')->set('pick', (string) $b->id)->assertSee('Bravo work')->assertDontSee('Alpha work')
        ->assertSet('groups', [])->assertSet('currency', 'INR')->assertCount('custom', 1);
    $t->set('pick', '')->assertDontSee('Bravo work');
});

it('uses the chosen invoice date, defaulting to today, and dates the due date from it', function () {
    $c = ibClient(['payment_terms' => 'net-15']);
    ibLine($c, 1000);
    $t = ibCreate($c)->assertSet('issuedOn', today()->toDateString());
    $t->set('issuedOn', 'nope')->call('save')->assertHasErrors('issuedOn');
    $t->set('issuedOn', '2026-10-01')->call('save')->assertHasNoErrors();
    $inv = Invoice::firstOrFail();
    expect($inv->issued_on->toDateString())->toBe('2026-10-01')->and($inv->due_on->toDateString())->toBe('2026-10-16');
});

it('removing work puts it back in the picker; removing a custom item sets it aside', function () {
    $c = ibClient();
    $a = ibLine($c, 10000, 'Alpha');
    $b = ibLine($c, 5000, 'Beta');
    $t = Livewire::withQueryParams(['client' => $c->id])->actingAs(ibOwner())->test(Create::class)->assertSet('tab', 'charges')
        ->set('pickCharges', [(string) $a->id, (string) $b->id])->call('addPicked')->assertCount('groups', 2)->assertSet('pickCharges', [])
        ->call('removeGroup', 0)->assertCount('groups', 1)->assertCount('parkedGroups', 0)->assertDontSee('Put back');
    $t->call('addAllWork')->assertCount('groups', 2)->assertSet('groups.1.label', 'Alpha'); // offered again
    $t->call('addCustom')->set('custom.0.description', 'Hosting')->set('custom.0.unit_price', '10')->call('removeCustom', 0)
        ->assertCount('custom', 0)->assertCount('parkedCustom', 1)->assertSee('Put back')->call('restoreCustom', 0)->assertSet('custom.0.description', 'Hosting');
    $t->call('addCustom')->call('removeCustom', 1)->assertCount('parkedCustom', 0); // blank line is just dropped
    $t->call('save')->assertHasNoErrors();
    $inv = Invoice::firstOrFail();
    expect($inv->total_cents)->toBe(16000)->and(array_column($inv->lines_snapshot, 'description'))->toBe(['Beta', 'Alpha', 'Hosting']);
});

it('hides quantity on the invoice unless asked, and keeps the choice', function () {
    $c = ibClient();
    ibLine($c, 1000, 'Work');
    ibCreate($c)->call('save');
    $inv = Invoice::firstOrFail();
    expect($inv->show_quantity)->toBeFalse();
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->assertDontSee('Unit price');
    $inv->update(['show_quantity' => true]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->assertSee('Unit price');
});

it('switches between not sent and sent by hand; never paid, never on a cancelled invoice', function () {
    Mail::fake();
    $c = ibClient();
    $inv = app(InvoiceService::class)->create($c, [ibLine($c, 1000)->id], [], 0, null, '83.5', null, true);
    $s = Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->assertSet('statusPick', 'draft')->assertSee('Not sent');
    $s->set('statusPick', 'sent')->assertDispatched('toast', type: 'success');
    expect($inv->fresh()->status)->toBe('sent')->and($inv->fresh()->emailed_at)->not->toBeNull(); // sending emails it once
    $s->set('statusPick', 'draft');
    expect($inv->fresh()->status)->toBe('draft');
    expect(app(InvoiceService::class)->setStatus($inv->fresh(), 'paid'))->toBeFalse();
    app(InvoiceService::class)->void($inv->fresh());
    expect(app(InvoiceService::class)->setStatus($inv->fresh(), 'sent'))->toBeFalse()->and($inv->fresh()->status)->toBe('void');
});

it('previews the total in the local currency by default, without saving it', function () {
    $c = ibClient();
    ibLine($c, 10000);
    $t = ibCreate($c)->assertSet('showLocal', true)->set('exchangeRate', '83.5')->assertSeeHtml('data-test="local-total"')->assertSee('₹8,350.00');
    $t->set('showLocal', false)->assertDontSeeHtml('data-test="local-total"');
});

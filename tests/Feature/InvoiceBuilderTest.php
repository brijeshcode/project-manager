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

beforeEach(fn () => $this->seed(RoleSeeder::class));

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
    return Livewire::withQueryParams(['client' => $c->id])->actingAs(ibOwner())->test(Create::class);
}

it('lists pending lines pre-ticked', function () {
    $c = ibClient();
    $a = ibLine($c, 10000, 'Alpha');
    $b = ibLine($c, 20000, 'Beta');
    ibCreate($c)->assertSee('Alpha')->assertSee('Beta')->assertSet('selected', [(string) $a->id, (string) $b->id]);
});

it('invoices ticked lines and leaves unticked pending', function () {
    $c = ibClient();
    $a = ibLine($c, 10000);
    $b = ibLine($c, 20000);
    ibCreate($c)->set('selected', [(string) $a->id])->call('save')->assertHasNoErrors()->assertRedirect();
    $inv = Invoice::first();
    expect($inv->total_cents)->toBe(10000);
    expect($a->fresh()->invoice_id)->toBe($inv->id);
    expect($b->fresh()->invoice_id)->toBeNull();
});

it('adds custom lines with bank, tax and INR rate and renders them', function () {
    $c = ibClient();
    ibLine($c, 100000, 'Build');
    $bank = ibBank();
    ibCreate($c)->call('addCustom')->set('custom.0.description', 'Extra')->set('custom.0.quantity', '2')->set('custom.0.unit_price', '400')
        ->set('taxPercent', '10')->set('bankAccountId', (string) $bank->id)->set('inrRate', '83.5')->call('save')->assertHasNoErrors();
    $inv = Invoice::first();
    expect([$inv->subtotal_cents, $inv->total_cents])->toBe([180000, 198000]);
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])
        ->assertSee('$1,980.00')->assertSee('Extra')->assertSee('HDFC')->assertSee('50100012341447')
        ->assertSee('INR')->assertSee('165,330'); // 1980.00 * 83.5 = 165330
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
    ibCreate($c)->set('selected', [(string) $foreign->id])->call('save')->assertHasErrors('lines');
    ibCreate($c)->set('selected', ['999999'])->call('save')->assertHasErrors('lines');
    ibCreate($c)->set('bankAccountId', '99999')->call('save')->assertHasErrors('bankAccountId');
    expect(Invoice::count())->toBe(0);
});

it('rejects malformed numerics and creates nothing', function () {
    $c = ibClient();
    ibLine($c, 1000);
    foreach (['1e3', 'abc', '-1', '999999999', 'NaN', '1.234', '101'] as $bad) {
        ibCreate($c)->set('taxPercent', $bad)->call('save')->assertHasErrors('taxPercent');
    }
    foreach (['1e3', 'abc', '-1', '999999999', '0', '1.23456'] as $bad) {
        ibCreate($c)->set('inrRate', $bad)->call('save')->assertHasErrors('inrRate');
        ibCreate($c)->call('addCustom')->set('custom.0.description', 'X')->set('custom.0.quantity', $bad)->set('custom.0.unit_price', '5')->call('save')->assertHasErrors('custom.0.quantity');
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
    Livewire::actingAs(ibOwner())->test(Show::class, ['invoice' => $inv])->call('void')->assertSee('void');
    expect($inv->fresh()->status)->toBe('void');
    Livewire::actingAs(ibOwner())->test(Index::class)->assertSee($inv->number)->assertSee('void');
});

it('lists newest first, filters by client and derives paid', function () {
    $a = ibClient();
    $b = ibClient(['name' => 'Bravo Ltd']);
    $svc = app(InvoiceService::class);
    $i1 = $svc->create($a, [ibLine($a, 1000)->id]);
    $i2 = $svc->create($b, [ibLine($b, 2000)->id]);
    \App\Models\Payment::create(['client_id' => $b->id, 'invoice_id' => $i2->id, 'amount_cents' => 2000, 'type' => 'project', 'paid_on' => today()]);
    Livewire::actingAs(ibOwner())->test(Index::class)->assertSeeInOrder([$i2->number, $i1->number])->assertSee('paid')
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

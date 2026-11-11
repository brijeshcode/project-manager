<?php

use App\Livewire\Payments\Form;
use App\Livewire\Payments\Index;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Services\ClientAccount;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    (new \App\Services\ExchangeRates)->setRate('USD', '83.5', User::factory()->create()); // USD clients need an active rate
});

function payOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function payClient(string $name = 'Acme'): Client
{
    return Client::create(['name' => $name, 'currency' => 'USD', 'billing_mode' => 'fixed']);
}

function payInvoice(Client $c, int $cents): Invoice
{
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);

    return app(InvoiceService::class)->create($c, [$l->id]);
}

function payForm(Client $c, array $o = [])
{
    return Livewire::test(Form::class)->set('client_id', $c->id)
        ->set('amount', $o['amount'] ?? '100.00')->set('paid_on', $o['paid_on'] ?? today()->toDateString())
        ->set('type', 'project')
        ->set('project_id', $o['project_id'] ?? null);
}

it("shows the owner's worked example: invoiced 200, pay 300 -> +100", function () {
    $this->actingAs(payOwner());
    $c = payClient();
    payInvoice($c, 20000);
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(-20000);
    payForm($c, ['amount' => '300'])->call('save')->assertHasNoErrors();
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(10000);
    expect(Payment::first()->amount_cents)->toBe(30000);
});

it('settles the client balance once payments reach what was invoiced', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    payInvoice($c, 20000);
    payForm($c, ['amount' => '100'])->call('save')->assertHasNoErrors();
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(-10000);
    payForm($c, ['amount' => '100'])->call('save')->assertHasNoErrors();
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(0);
});

it("rejects another client's project", function () {
    $this->actingAs(payOwner());
    $a = payClient('A');
    $b = payClient('B');
    $p = Project::create(['client_id' => $b->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    payForm($a, ['project_id' => $p->id])->call('save')->assertHasErrors('project_id');
    expect(Payment::count())->toBe(0);
});

it('rejects bad amounts and future dates without 500s', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    foreach (['0', '0.00', '-5', '1e3', 'abc', '99999999', '1.234'] as $amt) {
        payForm($c, ['amount' => $amt])->call('save')->assertHasErrors('amount');
    }
    payForm($c, ['paid_on' => today()->addDay()->toDateString()])->call('save')->assertHasErrors('paid_on');
    expect(Payment::count())->toBe(0);
});

it('is forbidden for non-owners on route and actions', function () {
    $c = payClient();
    foreach (['project_manager', 'developer', 'client'] as $role) {
        $this->actingAs(User::factory()->create()->assignRole($role))->get(route('payments.index'))->assertForbidden();
    }
    foreach (['project_manager', 'developer', 'client'] as $role) {
        $t = Livewire::actingAs(payOwner())->test(Form::class)->set('client_id', $c->id)->set('amount', '100')->set('paid_on', today()->toDateString());
        $this->actingAs(User::factory()->create()->assignRole($role));
        $t->call('save')->assertForbidden();
    }
    expect(Payment::count())->toBe(0);
});

it('escapes notes on the page', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 100, 'type' => 'project', 'paid_on' => today(), 'notes' => '<script>alert(1)</script>']);
    $this->get(route('payments.show', $p))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    $this->get(route('payments.edit', $p))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

it('has separate create and edit pages; the list only links to them', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    $this->get(route('payments.create', ['client' => $c->id]))->assertOk()->assertSee('Record payment')->assertSee('Show documents to client');
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 100, 'type' => 'project', 'paid_on' => today()]);
    $this->get(route('payments.edit', $p))->assertOk()->assertSee('Save changes');
    Livewire::test(Form::class)->assertSet('client_id', null);
    $this->get(route('payments.index'))->assertOk()->assertSee(route('payments.create'), false)
        ->assertDontSee('wire:submit="save"', false)->assertDontSee('id="search"', false)
        ->assertSee('aria-label="View payment"', false)->assertSee('aria-label="Edit payment"', false)->assertSee('aria-label="Delete payment"', false);
});

it('creates and redirects to the payment page', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    payForm($c)->call('save')->assertHasNoErrors()->assertRedirect(route('payments.show', Payment::firstOrFail()));
});

it('filters by client, type and paid date; stats follow the filters', function () {
    $this->travelTo('2026-11-11');
    $this->actingAs(payOwner());
    $a = payClient('A');
    $b = payClient('B');
    $mk = fn ($c, $cents, $date, $type = 'project') => Payment::create(['client_id' => $c->id, 'amount_cents' => $cents, 'currency' => 'USD', 'exchange_rate' => '1', 'local_amount_cents' => $cents, 'local_currency' => 'INR', 'paid_on' => $date, 'type' => $type]);
    $mk($a, 111100, '2026-11-05');
    $mk($a, 222200, '2026-05-05', 'maintenance');
    $mk($b, 444400, '2026-11-01');

    Livewire::test(Index::class)->assertSee('₹7,777.00')->assertSee('From 2 clients')
        ->set('clientFilter', (string) $a->id)->assertSee('₹3,333.00')->assertSee('₹1,666.50')->assertDontSee('4,444.00')->assertSee('A balance')
        ->set('typeFilter', 'maintenance')->assertSee('2,222.00')->assertDontSee('1,111.00')
        ->set('typeFilter', '')->call('setPeriod', 'month')->assertSet('from', '2026-11-01')->assertSee('1,111.00')->assertDontSee('2,222.00')
        ->call('clearFilters')->assertSet('clientFilter', '')->assertSet('from', '')->assertSee('₹7,777.00');
    Livewire::test(Index::class)->set('from', '2026-12-01')->assertSee('No matches');
});

it('flags payments without a rate in the stats', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    Payment::create(['client_id' => $c->id, 'amount_cents' => 100, 'currency' => 'USD', 'paid_on' => today(), 'type' => 'project']);
    Livewire::test(Index::class)->assertSee('1 without a rate')->assertSee('Set rate');
});

it('keeps the ?client= link from the invoice page as the list filter', function () {
    $this->actingAs(payOwner());
    $c = payClient('Zed');
    $this->get(route('payments.index', ['client' => $c->id]))->assertOk()->assertSee('Zed balance');
});

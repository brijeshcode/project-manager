<?php

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

beforeEach(fn () => $this->seed(RoleSeeder::class));

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
    return Livewire::test(Index::class)->set('client_id', $c->id)
        ->set('amount', $o['amount'] ?? '100.00')->set('paid_on', $o['paid_on'] ?? today()->toDateString())
        ->set('type', 'project')
        ->set('project_id', $o['project_id'] ?? null)->set('invoice_id', $o['invoice_id'] ?? null);
}

it("shows the owner's worked example: invoiced 200, pay 300 -> +100", function () {
    $this->actingAs(payOwner());
    $c = payClient();
    payInvoice($c, 20000);
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(-20000);
    payForm($c, ['amount' => '300'])->call('save')->assertHasNoErrors()->assertSee('$100.00');
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(10000);
    expect(Payment::first()->amount_cents)->toBe(30000);
});

it('marks the invoice paid only once the total is reached', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    $inv = payInvoice($c, 20000);
    payForm($c, ['amount' => '100', 'invoice_id' => $inv->id])->call('save')->assertHasNoErrors();
    expect($inv->fresh()->isPaid())->toBeFalse();
    payForm($c, ['amount' => '100', 'invoice_id' => $inv->id])->call('save')->assertHasNoErrors();
    expect($inv->fresh()->isPaid())->toBeTrue();
});

it("rejects another client's project and invoice, and a void invoice", function () {
    $this->actingAs(payOwner());
    $a = payClient('A');
    $b = payClient('B');
    $p = Project::create(['client_id' => $b->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $inv = payInvoice($b, 1000);
    payForm($a, ['project_id' => $p->id])->call('save')->assertHasErrors('project_id');
    payForm($a, ['invoice_id' => $inv->id])->call('save')->assertHasErrors('invoice_id');
    $own = payInvoice($a, 1000);
    app(InvoiceService::class)->void($own);
    payForm($a, ['invoice_id' => $own->id])->call('save')->assertHasErrors('invoice_id');
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
        $t = Livewire::actingAs(payOwner())->test(Index::class)->set('client_id', $c->id)->set('amount', '100')->set('paid_on', today()->toDateString());
        $this->actingAs(User::factory()->create()->assignRole($role));
        $t->call('save')->assertForbidden();
    }
    expect(Payment::count())->toBe(0);
});

it('escapes notes on the page', function () {
    $this->actingAs(payOwner());
    $c = payClient();
    Payment::create(['client_id' => $c->id, 'amount_cents' => 100, 'type' => 'project', 'paid_on' => today(), 'notes' => '<script>alert(1)</script>']);
    $this->get(route('payments.index'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});

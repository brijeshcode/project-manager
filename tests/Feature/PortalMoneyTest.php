<?php

use App\Enums\PaymentType;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function pmClient(string $name, string $role = 'client', bool $link = true): array
{
    $c = Client::create(['name' => $name, 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $u = User::factory()->create(['client_id' => $link ? $c->id : null])->assignRole($role);

    return [$c, $u];
}

function pmInvoice(Client $c, int $cents): Invoice
{
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);

    return app(InvoiceService::class)->create($c, [$l->id]);
}

function pmPay(Client $c, int $cents, array $o = []): Payment
{
    return Payment::create($o + ['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => '2026-11-01', 'type' => PaymentType::Project]);
}

it('lists only own sent invoices, hides other clients and void', function () {
    [$k, $ku] = pmClient('K');
    [$l] = pmClient('L');
    $own = pmInvoice($k, 11111);
    $void = pmInvoice($k, 22222);
    app(InvoiceService::class)->void($void);
    $other = pmInvoice($l, 33333);

    $this->actingAs($ku)->get('/portal/invoices')->assertOk()
        ->assertSee($own->number)->assertSee('$111.11')->assertSee(route('invoices.pdf', $own))
        ->assertDontSee($other->number)->assertDontSee('$333.33')
        ->assertDontSee($void->number)->assertDontSee('$222.22');
});

it('shows Paid or Due', function () {
    [$k, $ku] = pmClient('K');
    $inv = pmInvoice($k, 10000);
    $this->actingAs($ku)->get('/portal/invoices')->assertSee('Due');
    pmPay($k, 10000, ['invoice_id' => $inv->id]);
    $this->actingAs($ku)->get('/portal/invoices')->assertSee('Paid')->assertDontSee('Due');
});

it('client cannot fetch another clients pdf', function () {
    [$k, $ku] = pmClient('K');
    [$l] = pmClient('L');
    $this->actingAs($ku)->get(route('invoices.pdf', pmInvoice($k, 100)))->assertOk();
    $this->actingAs($ku)->get(route('invoices.pdf', pmInvoice($l, 100)))->assertNotFound();
});

it('lists only own payments and never notes', function () {
    [$k, $ku] = pmClient('K');
    [$l] = pmClient('L');
    $inv = pmInvoice($k, 50000);
    $p = Project::create(['client_id' => $k->id, 'name' => 'Site K', 'status' => 'active']);
    pmPay($k, 12345, ['invoice_id' => $inv->id, 'project_id' => $p->id, 'notes' => 'SECRET-NOTE']);
    pmPay($l, 77777, ['notes' => 'L-NOTE']);

    $this->actingAs($ku)->get('/portal/payments')->assertOk()
        ->assertSee('$123.45')->assertSee($inv->number)->assertSee('Site K')
        ->assertDontSee('SECRET-NOTE')->assertDontSee('$777.77')->assertDontSee('L-NOTE');
});

it('dashboard balance: credit, owe, settled', function () {
    [$k, $ku] = pmClient('K');
    $this->actingAs($ku)->get('/portal')->assertSee('All settled');
    pmInvoice($k, 20000);
    $this->actingAs($ku)->get('/portal')->assertSee('You owe')->assertSee('$200.00');
    pmPay($k, 30000);
    $this->actingAs($ku)->get('/portal')->assertSee('Credit')->assertSee('$100.00');
});

it('dashboard shows expected soft balance per project only when positive', function () {
    [$k, $ku] = pmClient('K');
    $p = Project::create(['client_id' => $k->id, 'name' => 'Site K', 'status' => 'active']);
    Module::create(['project_id' => $p->id, 'name' => 'M', 'budget_amount_cents' => 100000]);
    pmPay($k, 30000, ['project_id' => $p->id]);
    $this->actingAs($ku)->get('/portal')->assertSee('Expected')->assertSee('$700.00');
    pmPay($k, 70000, ['project_id' => $p->id]);
    $this->actingAs($ku)->get('/portal')->assertDontSee('Expected');
});

it('portal money pages leak no internals', function () {
    [$k, $ku] = pmClient('K');
    pmInvoice($k, 100);
    pmPay($k, 100);
    foreach (['/portal', '/portal/invoices', '/portal/payments'] as $url) {
        $html = strtolower($this->actingAs($ku)->get($url)->assertOk()->getContent());
        expect($html)->not->toContain('profit')->not->toContain('cost')->not->toContain('source_type');
    }
});

it('gates the new routes', function () {
    [, $nolink] = pmClient('X', 'client', false);
    foreach (['/portal/invoices', '/portal/payments'] as $url) {
        $this->actingAs($nolink)->get($url)->assertForbidden();
        foreach (['super_admin', 'project_manager', 'developer'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role))->get($url)->assertForbidden();
        }
    }
});

it('redirects guests to login', function () {
    $this->get('/portal/invoices')->assertRedirect('/login');
    $this->get('/portal/payments')->assertRedirect('/login');
});

<?php

use App\Enums\AccessScope;
use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
});

function lfClient(string $name): Client
{
    return Client::create(['name' => $name, 'currency' => 'USD', 'billing_mode' => 'fixed']);
}

function lfInvoice(Client $c, string $number, string $status = 'sent', int $total = 10000): Invoice
{
    return Invoice::create([
        'number' => $number, 'client_id' => $c->id, 'status' => $status, 'issued_on' => today(), 'currency' => 'USD',
        'subtotal_cents' => $total, 'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => $total,
    ]);
}

function lfPayment(Client $c, int $cents = 500, array $o = []): Payment
{
    return Payment::create($o + ['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => today(), 'type' => 'project']);
}

// ---- clients
it('clients: search narrows and % is literal', function () {
    lfClient('Alpha Corp'); lfClient('Beta Ltd'); lfClient('100% Pure');
    Livewire::test(ClientsIndex::class)->assertOk()->assertSee('Alpha Corp')->assertSee('Beta Ltd')
        ->set('search', 'alp')->assertSee('Alpha Corp')->assertDontSee('Beta Ltd')
        ->set('search', '%')->assertSee('100% Pure')->assertDontSee('Alpha Corp')
        ->set('search', 'zzz')->assertSee('No matches')->assertSee('Clear filters');
});

it('clients: shows nothing-yet state with no rows', function () {
    Livewire::test(ClientsIndex::class)->assertOk()->assertSee('No clients yet')->assertDontSee('Clear filters');
});

it('clients: page 2 reachable, page 1 item absent, beyond-last does not error', function () {
    foreach (range(1, 16) as $i) { lfClient(sprintf('Client %02d', $i)); }
    Livewire::test(ClientsIndex::class)->assertOk()->assertSee('Client 01')->assertDontSee('Client 16')
        ->call('gotoPage', 2)->assertOk()->assertSee('Client 16')->assertDontSee('Client 01')
        ->call('gotoPage', 99)->assertOk();
});

// ---- projects
it('projects: search by name or client, status filter, % literal', function () {
    $a = lfClient('Acme'); $b = lfClient('Bolt');
    Project::create(['client_id' => $a->id, 'name' => 'Website', 'status' => 'active']);
    Project::create(['client_id' => $b->id, 'name' => 'Mobile', 'status' => 'on_hold']);
    Project::create(['client_id' => $b->id, 'name' => '50% Off', 'status' => 'active']);
    Livewire::test(ProjectsIndex::class)->assertOk()->assertSee('Website')->assertSee('Mobile')
        ->set('search', 'web')->assertSee('Website')->assertDontSee('Mobile')
        ->set('search', 'bolt')->assertSee('Mobile')->assertDontSee('Website')
        ->set('search', '%')->assertSee('50% Off')->assertDontSee('Mobile')
        ->set('search', '')->set('status', 'on_hold')->assertSee('Mobile')->assertDontSee('Website')
        ->set('search', 'nothing')->assertSee('No matches');
});

it('projects: developer search only sees member projects; paginates 12', function () {
    $c = lfClient('Acme');
    $mine = Project::create(['client_id' => $c->id, 'name' => 'Mine App', 'status' => 'active']);
    Project::create(['client_id' => $c->id, 'name' => 'Other App', 'status' => 'active']);
    $dev = User::factory()->create()->assignRole('developer');
    ProjectMember::create(['project_id' => $mine->id, 'user_id' => $dev->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    $this->actingAs($dev);
    Livewire::test(ProjectsIndex::class)->assertOk()->assertSee('Mine App')->assertDontSee('Other App')
        ->set('search', 'App')->assertSee('Mine App')->assertDontSee('Other App');

    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    foreach (range(1, 12) as $i) { Project::create(['client_id' => $c->id, 'name' => sprintf('Proj %02d', $i), 'status' => 'active']); }
    Livewire::test(ProjectsIndex::class)->assertOk()->assertSee('Mine App')->assertDontSee('Proj 12')
        ->call('gotoPage', 2)->assertSee('Proj 12')->assertDontSee('Mine App')
        ->call('gotoPage', 50)->assertOk();
});

// ---- invoices
it('invoices: search by number/client, status filters incl. paid/unpaid', function () {
    $a = lfClient('Acme'); $b = lfClient('Bolt');
    $paid = lfInvoice($a, 'INV-PAID', 'sent', 10000);
    lfPayment($a, 10000, ['invoice_id' => $paid->id]);
    lfInvoice($a, 'INV-OPEN', 'sent', 10000);
    lfInvoice($b, 'INV-VOID', 'void', 10000);
    Livewire::test(InvoicesIndex::class)->assertOk()->assertSee('INV-PAID')->assertSee('INV-OPEN')->assertSee('INV-VOID')
        ->set('search', 'inv-open')->assertSee('INV-OPEN')->assertDontSee('INV-PAID')
        ->set('search', 'bolt')->assertSee('INV-VOID')->assertDontSee('INV-OPEN')
        ->set('search', '')->set('status', 'void')->assertSee('INV-VOID')->assertDontSee('INV-OPEN')
        ->set('status', 'sent')->assertSee('INV-OPEN')->assertDontSee('INV-VOID')
        ->set('status', 'paid')->assertSee('INV-PAID')->assertDontSee('INV-OPEN')->assertDontSee('INV-VOID')
        ->set('status', 'unpaid')->assertSee('INV-OPEN')->assertDontSee('INV-PAID')->assertDontSee('INV-VOID')
        ->set('status', '')->set('search', '%')->assertSee('No matches')->assertSee('Clear filters');
});

it('invoices: % literal in client name, page 2, beyond-last safe', function () {
    $p = lfClient('100% Pure'); $o = lfClient('Other');
    lfInvoice($p, 'INV-PCT'); lfInvoice($o, 'INV-OTHER');
    Livewire::test(InvoicesIndex::class)->assertOk()->set('search', '%')->assertSee('INV-PCT')->assertDontSee('INV-OTHER');
    foreach (range(1, 15) as $i) { lfInvoice($o, sprintf('INV-X%02d', $i)); }
    Livewire::test(InvoicesIndex::class)->assertOk()->assertDontSee('INV-PCT')
        ->call('gotoPage', 2)->assertSee('INV-PCT')->assertDontSee('INV-X15')
        ->call('gotoPage', 99)->assertOk();
});

// ---- payments
it('payments: search by client name, never notes', function () {
    $a = lfClient('Acme'); $b = lfClient('Bolt');
    lfPayment($a, 111100, ['notes' => 'secretnote']);
    lfPayment($b, 222200);
    Livewire::test(PaymentsIndex::class)->assertOk()->assertSee('1,111.00')->assertSee('2,222.00')
        ->set('search', 'acme')->assertSee('1,111.00')->assertDontSee('2,222.00')
        ->set('search', 'secretnote')->assertDontSee('1,111.00')->assertDontSee('2,222.00')->assertSee('No matches');
});

it('payments: % literal, page 2, beyond-last safe', function () {
    $p = lfClient('100% Pure'); $o = lfClient('Other');
    lfPayment($p, 777700, ['paid_on' => today()->subDays(30)]);
    Livewire::test(PaymentsIndex::class)->assertOk()->set('search', '%')->assertSee('7,777.00');
    foreach (range(1, 15) as $i) { lfPayment($o, 100, ['paid_on' => today()]); }
    Livewire::test(PaymentsIndex::class)->assertOk()->assertDontSee('7,777.00')
        ->call('gotoPage', 2)->assertSee('7,777.00')
        ->call('gotoPage', 99)->assertOk();
});

it('clients cannot reach invoices or payments lists', function () {
    $this->actingAs(User::factory()->create()->assignRole('client'));
    $this->get(route('invoices.index'))->assertForbidden();
    $this->get(route('payments.index'))->assertForbidden();
});

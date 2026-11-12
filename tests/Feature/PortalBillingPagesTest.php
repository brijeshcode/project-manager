<?php

use App\Enums\PaymentType;
use App\Enums\RecurringFrequency;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function pbClient(string $name, string $role = 'client'): array
{
    $c = Client::create(['name' => $name, 'currency' => 'USD', 'billing_mode' => 'fixed']);

    return [$c, User::factory()->create(['client_id' => $c->id])->assignRole($role)];
}

function pbInvoice(Client $c, int $cents): Invoice
{
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);

    return app(InvoiceService::class)->create($c, [$l->id]);
}

function pbPay(Client $c, int $cents, array $o = []): Payment
{
    return Payment::create($o + ['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => '2026-11-01', 'type' => PaymentType::Project]);
}

it('shows own sent invoice details, 404s other clients and drafts', function () {
    [$k, $ku] = pbClient('K');
    [$l] = pbClient('L');
    $own = pbInvoice($k, 12345);
    $draft = pbInvoice($k, 500);
    $draft->update(['status' => 'draft']);
    $other = pbInvoice($l, 999);

    $this->actingAs($ku)->get(route('portal.invoice', $own))->assertOk()->assertSee($own->number)->assertSee(route('invoices.pdf', $own));
    $this->actingAs($ku)->get(route('portal.invoice', $draft))->assertNotFound();
    $this->actingAs($ku)->get(route('portal.invoice', $other))->assertNotFound();
    $this->actingAs($ku)->get('/portal/invoices')->assertSee(route('portal.invoice', $own));
});

it('shows own payment details without notes, 404s other clients', function () {
    [$k, $ku] = pbClient('K');
    [$l] = pbClient('L');
    $own = pbPay($k, 4200, ['method' => 'card', 'notes' => 'INTERNAL-NOTE-XYZ']);
    $other = pbPay($l, 100);

    $this->actingAs($ku)->get(route('portal.payment', $own))->assertOk()
        ->assertSee('$42.00')->assertSee('Card')->assertDontSee('INTERNAL-NOTE-XYZ');
    $this->actingAs($ku)->get(route('portal.payment', $other))->assertNotFound();
    $this->actingAs($ku)->get('/portal/payments')->assertSee(route('portal.payment', $own));
});

it('renders statement and subscriptions for the client only', function () {
    [$k, $ku] = pbClient('K');
    [$l] = pbClient('L');
    $inv = pbInvoice($k, 10000);
    pbPay($k, 4000);
    RecurringCharge::create(['client_id' => $k->id, 'description' => 'Hosting plan', 'amount_cents' => 1500, 'frequency' => RecurringFrequency::Monthly, 'next_due_on' => now()->addMonth(), 'active' => true]);
    RecurringCharge::create(['client_id' => $l->id, 'description' => 'Other client plan', 'amount_cents' => 1, 'frequency' => RecurringFrequency::Monthly, 'next_due_on' => now()->addMonth(), 'active' => true]);

    $this->actingAs($ku)->get('/portal/statement')->assertOk()
        ->assertSee(route('portal.invoice', $inv))->assertSee('-$60.00')->assertDontSee(route('invoices.show', $inv));
    $this->actingAs($ku)->get('/portal/subscriptions')->assertOk()
        ->assertSee('Hosting plan')->assertSee('All projects')->assertDontSee('Other client plan');

    $dev = User::factory()->create()->assignRole('developer');
    $this->actingAs($dev)->get('/portal/statement')->assertForbidden();
    $this->actingAs($dev)->get('/portal/subscriptions')->assertForbidden();
});

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
    $c = Client::create(['name' => $name, 'currency' => 'USD', 'billing_mode' => 'fixed', 'portal_features' => array_keys(Client::PORTAL_FEATURES)]);

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

it('invoice stats and search count only own sent invoices', function () {
    [$k, $ku] = pbClient('K');
    [$l] = pbClient('L');
    $a = pbInvoice($k, 10000);
    pbInvoice($k, 30000)->update(['status' => 'draft']);
    pbInvoice($l, 77700);

    $page = Livewire\Livewire::actingAs($ku)->test(\App\Livewire\Portal\Invoices::class)
        ->assertSeeInOrder(['Total invoiced', '$100.00', '1 invoice'])->assertDontSee('$300.00')->assertDontSee('$777.00');
    $page->set('search', 'nope')->assertSee('No matches')->set('search', $a->number)->assertSee(route('portal.invoice', $a));
});

it('payment stats are in the payment currency and filter by type', function () {
    [$k, $ku] = pbClient('K');
    [$l] = pbClient('L');
    pbPay($k, 5000, ['exchange_rate' => '83.5', 'local_amount_cents' => 417500, 'local_currency' => 'INR']);
    pbPay($k, 2000, ['type' => PaymentType::Maintenance]);
    pbPay($l, 99900);

    Livewire\Livewire::actingAs($ku)->test(\App\Livewire\Portal\Payments::class)
        ->assertSeeInOrder(['Total paid', '$70.00'])->assertDontSee('4,175')->assertDontSee('$999.00')
        ->set('typeFilter', 'maintenance')->assertSeeInOrder(['Total paid', '$20.00']);
});

it('subscriptions list shows stats and filters, hides internal flags', function () {
    [$k, $ku] = pbClient('K');
    RecurringCharge::create(['client_id' => $k->id, 'description' => 'Hosting', 'amount_cents' => 5000, 'frequency' => 'monthly', 'next_due_on' => today()->addDays(5), 'active' => true, 'pass_through' => true]);
    RecurringCharge::create(['client_id' => $k->id, 'description' => 'Domain', 'amount_cents' => 1200, 'frequency' => 'yearly', 'next_due_on' => today()->addMonths(3), 'active' => false]);

    Livewire\Livewire::actingAs($ku)->test(\App\Livewire\Portal\Subscriptions::class)
        ->assertSeeInOrder(['Active services', '1', 'Monthly cost', '$50.00'])->assertDontSee('Pass-through')
        ->set('status', 'paused')->assertSee('Domain')->assertDontSee('Hosting')
        ->set('status', '')->set('search', 'host')->assertSee('Hosting')->assertDontSee('Domain');
});

it('subscriptions open on active ones, or on all while one awaits approval; the admin list opens on active', function () {
    [$k, $ku] = pbClient('K');
    RecurringCharge::create(['client_id' => $k->id, 'description' => 'Hosting', 'amount_cents' => 5000, 'frequency' => 'monthly', 'next_due_on' => today()->addDays(5), 'active' => true]);
    $domain = RecurringCharge::create(['client_id' => $k->id, 'description' => 'Domain', 'amount_cents' => 1200, 'frequency' => 'yearly', 'next_due_on' => today()->addMonths(3), 'active' => false]);

    Livewire\Livewire::actingAs($ku)->test(\App\Livewire\Portal\Subscriptions::class)->assertSet('status', 'active')->assertSee('Hosting')->assertDontSee('Domain');
    Livewire\Livewire::actingAs(User::factory()->create()->assignRole('super_admin'))->test(\App\Livewire\Billing\Recurring::class)
        ->assertSet('status', 'active')->assertSee('Hosting')->assertDontSee('Domain');

    $domain->update(['client_state' => RecurringCharge::AWAITING]);
    Livewire\Livewire::actingAs($ku)->test(\App\Livewire\Portal\Subscriptions::class)->assertSet('status', '')->assertSee('Domain');
});

it('subscription page shows dates and skipped periods but never draft invoices', function () {
    [$k, $ku] = pbClient('K');
    $c = RecurringCharge::create(['client_id' => $k->id, 'description' => 'Hosting', 'amount_cents' => 5000, 'frequency' => 'monthly', 'next_due_on' => '2026-01-01', 'starts_on' => '2026-01-01', 'active' => true, 'skipped_periods' => ['2026-02']]);
    $draft = app(InvoiceService::class)->create($k, [$c->lineFor('2026-01')->id], draft: true);

    $this->actingAs($ku)->get(route('portal.subscription', $c->id))->assertOk()
        ->assertSee('Started Jan 1, 2026')->assertSee('Feb 2026')->assertSee('Skipped')->assertDontSee($draft->number);
});

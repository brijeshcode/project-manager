<?php

use App\Livewire\Clients\Show;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\ClientAccount;
use App\Services\ExchangeRates;
use App\Support\ClientStatement;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->c = Client::create(['name' => 'Acme', 'currency' => 'USD']);
});

function stInvoice(Client $c, int $total, string $on, string $status = 'sent', string $n = 'INV-1'): Invoice
{
    return Invoice::create(['number' => $n, 'client_id' => $c->id, 'status' => $status, 'issued_on' => $on, 'currency' => 'USD', 'subtotal_cents' => $total, 'tax_cents' => 0, 'total_cents' => $total]);
}

function stPay(Client $c, int $cents, string $on, array $o = []): Payment
{
    return Payment::create($o + ['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => $on, 'type' => 'project']);
}

it('orders sent invoices and payments by date with debit, credit and running balance', function () {
    stPay($this->c, 30000, '2026-03-10');
    stInvoice($this->c, 100000, '2026-03-01', 'sent', 'A');
    stInvoice($this->c, 50000, '2026-03-10', 'sent', 'B');
    stInvoice($this->c, 99900, '2026-03-05', 'draft', 'D');
    stInvoice($this->c, 77700, '2026-03-06', 'void', 'V');

    $rows = ClientStatement::rows($this->c);
    expect($rows->map(fn ($r) => [$r['type'], $r['amount'], $r['balance']])->all())->toBe([
        ['invoice', 100000, -100000],
        ['invoice', 50000, -150000],
        ['payment', 30000, -120000],
    ])->and($rows->last()['balance'])->toBe(app(ClientAccount::class)->balanceCents($this->c));
});

it('closing balance equals the client balance with foreign-currency payments', function () {
    (new ExchangeRates)->setRate('USD', '80', $this->admin);
    (new ExchangeRates)->setRate('EUR', '100', $this->admin);
    stInvoice($this->c, 100000, '2026-01-01');
    foreach (['2026-01-02', '2026-01-03', '2026-01-04'] as $d) {
        stPay($this->c, 9000, $d, ['currency' => 'EUR', 'local_amount_cents' => 10050, 'local_currency' => \App\Services\LocalCurrency::code()]);
    }

    $rows = ClientStatement::rows($this->c);
    expect($rows->where('type', 'payment')->pluck('amount')->all())->toBe([126, 125, 126])
        ->and($rows->last()['balance'])->toBe(app(ClientAccount::class)->balanceCents($this->c));
});

it('renders the statement with opening balance carried from before the filter', function () {
    stInvoice($this->c, 100000, '2026-01-15', 'sent', 'INV-OLD');
    stPay($this->c, 40000, '2026-02-01');
    stInvoice($this->c, 25000, '2026-03-02', 'sent', 'INV-NEW');
    stInvoice($this->c, 99900, '2026-03-03', 'draft', 'INV-DRAFT');

    $t = Livewire::actingAs($this->admin)->test(Show::class, ['client' => $this->c])
        ->assertSee(['Statement', 'Opening balance', 'Closing balance', 'INV-OLD', 'Owing'])
        ->assertSeeHtml('data-test="statement-closing">-$850.00');

    $t->set('from', '2026-03-01')->set('to', '2026-03-31')
        ->assertSee('-$600.00')
        ->assertSeeHtml('data-test="statement-debit">$250.00')
        ->assertSeeHtml('data-test="statement-closing">-$850.00')
        ->assertViewHas('statement', fn ($s) => $s['rows']->pluck('model.number')->all() === ['INV-NEW'] && $s['opening'] === -60000);
});

it('shows an empty state when there are no transactions', function () {
    Livewire::actingAs($this->admin)->test(Show::class, ['client' => $this->c])->assertSee('No transactions yet')->assertSee('No subscriptions yet');
});

it('lists subscriptions with times and total invoiced, excluding cancelled invoices', function () {
    $p = Project::create(['client_id' => $this->c->id, 'name' => 'Site', 'start_date' => now(), 'status' => 'active']);
    $s = RecurringCharge::create(['client_id' => $this->c->id, 'project_id' => $p->id, 'description' => 'Hosting', 'amount_cents' => 2000, 'frequency' => 'monthly', 'next_due_on' => '2026-12-01', 'active' => true]);
    RecurringCharge::create(['client_id' => $this->c->id, 'description' => 'Support plan', 'amount_cents' => 50000, 'frequency' => 'yearly', 'next_due_on' => '2027-01-01', 'active' => false]);
    $sent = stInvoice($this->c, 4000, '2026-10-01', 'sent', 'A');
    $void = stInvoice($this->c, 2000, '2026-10-02', 'void', 'V');
    foreach ([['2026-09', $sent], ['2026-10', $sent], ['2026-11', $void]] as [$per, $inv]) {
        BillableLine::create(['client_id' => $this->c->id, 'recurring_charge_id' => $s->id, 'period' => $per, 'invoice_id' => $inv->id, 'description' => 'Hosting', 'quantity' => 1, 'unit_price_cents' => 2000, 'amount_cents' => 2000]);
    }

    Livewire::actingAs($this->admin)->test(Show::class, ['client' => $this->c])
        ->assertSee(['Hosting', 'Site', 'Monthly', 'Active', 'Dec 1, 2026', '$40.00', '2 times'])
        ->assertSee(['Support plan', 'Client-wide', 'Yearly', 'Paused'])
        ->assertSeeHtml(route('billing.recurring.show', ['charge' => $s->id]))
        ->assertSeeHtml(e(route('billing.recurring.create', ['client' => $this->c->id])));
});

it('is forbidden to users without money access', function () {
    $u = User::factory()->create()->assignRole('project_manager');
    $this->actingAs($u)->get(route('clients.show', $this->c))->assertForbidden();
});

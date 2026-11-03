<?php

use App\Livewire\Invoices\Create;
use App\Livewire\Invoices\Show;
use App\Livewire\Payments\Index as PayIndex;
use App\Livewire\Projects\Show as ProjectShow;
use App\Mail\InvoiceMail;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\BillableService;
use App\Services\ClientAccount;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    (new \App\Services\ExchangeRates)->setRate('USD', '83.5', User::factory()->create()->assignRole('super_admin'));
    Mail::fake();
});

function ffOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function ffClient(string $mode = 'fixed', string $name = 'Acme'): Client
{
    return Client::create(['name' => $name, 'currency' => 'USD', 'billing_mode' => $mode, 'contact_email' => 'a@acme.test']);
}

function ffLine(Client $c, array $o = []): BillableLine
{
    return BillableLine::create($o + ['client_id' => $c->id, 'description' => 'Work item', 'quantity' => 2, 'unit_price_cents' => 5000, 'amount_cents' => 10000]);
}

// ---- I-1 snapshot
it('stores a lines snapshot matching its lines', function () {
    $c = ffClient();
    $inv = app(InvoiceService::class)->create($c, [ffLine($c)->id], [['description' => 'Custom', 'quantity' => '1', 'unit_price' => '5.00']]);
    $snap = $inv->fresh()->lines_snapshot;
    expect($snap)->toHaveCount(2);
    expect(collect($snap)->pluck('description')->all())->toEqualCanonicalizing(['Work item', 'Custom']);
    expect(collect($snap)->sum('amount_cents'))->toBe(10500);
    expect(array_keys($snap[0]))->toEqualCanonicalizing(['description', 'quantity', 'unit_price_cents', 'amount_cents', 'project_id']);
});

it('voided invoice still lists lines with a VOID marker, and owner pdf works', function () {
    $c = ffClient();
    $inv = app(InvoiceService::class)->create($c, [ffLine($c, ['description' => 'Kept line'])->id]);
    app(InvoiceService::class)->void($inv);
    expect($inv->fresh()->lines()->count())->toBe(0);
    Livewire::actingAs(ffOwner())->test(Show::class, ['invoice' => $inv])->assertSee('Kept line')->assertSee('$100.00')->assertSee('VOID');
    $r = $this->actingAs(ffOwner())->get(route('invoices.pdf', $inv))->assertOk();
    expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

it('editing a line after invoicing does not change the invoice', function () {
    $c = ffClient();
    $l = ffLine($c, ['description' => 'Original']);
    $inv = app(InvoiceService::class)->create($c, [$l->id]);
    $l->update(['description' => 'Changed', 'amount_cents' => 1]);
    Livewire::actingAs(ffOwner())->test(Show::class, ['invoice' => $inv])->assertSee('Original')->assertDontSee('Changed');
    $html = (new InvoiceMail($inv->fresh()))->render();
    expect($html)->toContain('Original')->not->toContain('Changed');
});

it('falls back to live lines for legacy invoices without a snapshot', function () {
    $c = ffClient();
    $inv = app(InvoiceService::class)->create($c, [ffLine($c, ['description' => 'Legacy line'])->id]);
    $inv->update(['lines_snapshot' => null]);
    Livewire::actingAs(ffOwner())->test(Show::class, ['invoice' => $inv])->assertSee('Legacy line');
});

// ---- I-2 discard
it('discards a pending custom line', function () {
    $c = ffClient();
    $l = ffLine($c, ['description' => 'Stray custom']);
    Livewire::actingAs(ffOwner())->withQueryParams(['client' => $c->id])->test(Create::class)
        ->assertSee('Stray custom')->call('discardLine', $l->id)->assertDontSee('Stray custom')->assertSet('selected', []);
    expect(BillableLine::find($l->id))->toBeNull();
});

it('refuses to discard task-sourced, other-client or invoiced lines', function () {
    $c = ffClient();
    $o = ffClient('fixed', 'Other');
    $task = ffLine($c, ['source_type' => 'task', 'source_id' => 1]);
    $other = ffLine($o);
    $done = ffLine($c);
    app(InvoiceService::class)->create($c, [$done->id]);
    $t = Livewire::actingAs(ffOwner())->withQueryParams(['client' => $c->id])->test(Create::class);
    foreach ([$task->id, $other->id, $done->id, 999999] as $id) {
        expect(fn () => $t->call('discardLine', $id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    }
    expect(BillableLine::whereIn('id', [$task->id, $other->id, $done->id])->count())->toBe(3);
});

it('discardLine re-authorizes', function () {
    $c = ffClient();
    $l = ffLine($c);
    $t = Livewire::actingAs(ffOwner())->withQueryParams(['client' => $c->id])->test(Create::class);
    $this->actingAs(User::factory()->create()->assignRole('developer'));
    $t->call('discardLine', $l->id)->assertForbidden();
    expect(BillableLine::find($l->id))->not->toBeNull();
});

// ---- I-3 budget bounds
function ffModule(string $mode = 'hourly'): Module
{
    $c = ffClient($mode);
    $p = Project::create(['client_id' => $c->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);

    return Module::create(['project_id' => $p->id, 'name' => 'M1', 'position' => 1]);
}

function ffPortal(Client $c): User
{
    return User::factory()->create(['client_id' => $c->id])->assignRole('client');
}

it('accepts budgets at the bounds and pages render', function () {
    $m = ffModule();
    Livewire::actingAs(ffOwner())->test(ProjectShow::class, ['project' => $m->project])
        ->set("budget.{$m->id}.hours", '99999.99')->set("budget.{$m->id}.rate", '9999999.99')
        ->call('saveBudget', $m->id)->assertHasNoErrors()->assertOk();
    expect($m->fresh()->budgetCents())->not->toBeNull();
    $this->actingAs(ffPortal($m->project->client))->get(route('portal.dashboard'))->assertOk();
});

it('rejects budgets above the bounds', function (string $field, string $val) {
    $m = ffModule();
    Livewire::actingAs(ffOwner())->test(ProjectShow::class, ['project' => $m->project])
        ->set("budget.{$m->id}.$field", $val)->call('saveBudget', $m->id)->assertHasErrors("budget.{$m->id}.$field");
})->with([['hours', '100000'], ['rate', '10000000'], ['rate', '1,00000000'], ['amount', '10000000'], ['hours', '1.234']]);

it('does not 500 on absurd rows forced into the db', function () {
    $m = ffModule();
    Module::whereKey($m->id)->update(['budget_hours' => 999999, 'hourly_rate_cents' => 99999999999]);
    expect($m->fresh()->budgetCents())->toBeNull();
    Livewire::actingAs(ffOwner())->test(ProjectShow::class, ['project' => $m->project])->assertOk();
    $this->actingAs(ffPortal($m->project->client))->get(route('portal.dashboard'))->assertOk();
});

it('softBalanceCents does not overflow when summing', function () {
    $m = ffModule('fixed');
    Module::whereKey($m->id)->update(['budget_amount_cents' => PHP_INT_MAX]);
    Module::create(['project_id' => $m->project_id, 'name' => 'M2', 'position' => 2, 'budget_amount_cents' => PHP_INT_MAX]);
    expect(app(ClientAccount::class)->softBalanceCents($m->project->fresh()))->toBeInt();
});

// ---- I-4 delete payment
function ffPay(Client $c, int $cents, ?int $inv = null): Payment
{
    return Payment::create(['client_id' => $c->id, 'invoice_id' => $inv, 'amount_cents' => $cents, 'paid_on' => '2026-11-01', 'type' => 'project', 'notes' => 'secret-note']);
}

it('deleting a payment restores the client balance', function () {
    $c = ffClient();
    $inv = app(InvoiceService::class)->create($c, [ffLine($c)->id]);
    $p = ffPay($c, 10000, $inv->id);
    $acct = app(ClientAccount::class);
    expect($acct->balanceCents($c))->toBe(0);
    Log::spy();
    Livewire::actingAs(ffOwner())->test(PayIndex::class)->assertSee('Delete')->call('deletePayment', $p->id)->assertOk();
    expect($acct->balanceCents($c))->toBe(-10000)->and(Payment::count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx = []) => $m === 'payment deleted'
        && $ctx['id'] === $p->id && $ctx['amount_cents'] === 10000 && ! str_contains(json_encode($ctx), 'secret-note'))->once();
});

it('deletePayment re-authorizes and 404s on unknown ids', function () {
    $c = ffClient();
    $p = ffPay($c, 500);
    $t = Livewire::actingAs(ffOwner())->test(PayIndex::class);
    expect(fn () => $t->call('deletePayment', 999999))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->actingAs(User::factory()->create()->assignRole('project_manager'));
    $t->call('deletePayment', $p->id)->assertForbidden();
    expect(Payment::find($p->id))->not->toBeNull();
});

// ---- minors
it('bulk claim rejects a line that was claimed concurrently', function () {
    $c = ffClient();
    $l = ffLine($c);
    $other = app(InvoiceService::class)->create($c, [ffLine($c)->id]);
    // select-then-claim race: the line is claimed by $other between pick and update
    $claimed = false;
    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$claimed, $l, $other) {
        if (! $claimed && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'billable_lines') && str_contains($q->sql, 'in (')) {
            $claimed = true;
            \Illuminate\Support\Facades\DB::table('billable_lines')->where('id', $l->id)->update(['invoice_id' => $other->id]);
        }
    });
    expect(fn () => app(InvoiceService::class)->create($c, [$l->id]))->toThrow(ValidationException::class);
    expect(\App\Models\Invoice::count())->toBe(1); // the second invoice rolled back
});

it('balance is unchanged by line sync and recurring generation', function () {
    $c = ffClient();
    ffPay($c, 700);
    RecurringCharge::create(['client_id' => $c->id, 'description' => 'Hosting', 'amount_cents' => 5000, 'frequency' => 'monthly', 'next_due_on' => '2026-09-01', 'active' => true]);
    $acct = app(ClientAccount::class);
    $before = $acct->balanceCents($c);
    app(BillableService::class)->syncCompletedWork($c);
    foreach (RecurringCharge::all() as $r) {
        expect($r->generateDue(today()))->toBeGreaterThan(0);
    }
    expect(BillableLine::count())->toBeGreaterThan(0)->and($acct->balanceCents($c))->toBe($before);
});

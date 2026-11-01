<?php

use App\Enums\PaymentType;
use App\Models\Client;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function dbPay(Client $c, int $cents, string $on, ?int $local, string $type = 'project'): void
{
    Payment::create(['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => $on, 'type' => PaymentType::Project,
        'local_amount_cents' => $local, 'local_currency' => $local === null ? null : 'INR', 'exchange_rate' => $local === null ? null : '1']);
}

function dbUser(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

it('owner sees single local-currency money cards, never a second currency line', function () {
    $usd = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inr = Client::create(['name' => 'Bharat', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    dbPay($usd, 123400, now()->toDateString(), 10_000_000);
    dbPay($inr, 556600, now()->toDateString(), 556600);

    $r = actingAs(dbUser('super_admin'))->get('/dashboard');
    $r->assertOk();
    $r->assertSee('Received')->assertSee('Outstanding')->assertSee('Active clients')->assertSee('This month');
    $r->assertSee('₹105,566.00', false)->assertDontSee('$1,234.00', false)->assertDontSee('₹5,566.00', false);
    $r->assertDontSee('no exchange rate')->assertDontSee('without a rate');
});

it('dashboard shows notices only when payments or clients lack a rate', function () {
    $usd = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $eur = Client::create(['name' => 'Euro', 'currency' => 'EUR', 'billing_mode' => 'fixed']);
    dbPay($usd, 1000, now()->toDateString(), null);
    $l = \App\Models\BillableLine::create(['client_id' => $eur->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 5000, 'amount_cents' => 5000]);
    app(\App\Services\InvoiceService::class)->create($eur, [$l->id])->update(['status' => 'sent']);

    actingAs(dbUser('super_admin'))->get('/dashboard')->assertOk()
        ->assertSee('1 payment has no exchange rate')->assertSee(route('payments.index'), false)
        ->assertSee('1 client without a rate');
});

it('owner period falls back to 30 when invalid', function () {
    actingAs(dbUser('super_admin'));
    Livewire::test('dashboard')->set('period', 5)->assertSet('period', 30)->assertOk();
    Livewire::test('dashboard')->set('period', 90)->assertSet('period', 90);
});

it('dashboard component forbids non-owners', function () {
    actingAs(dbUser('developer'));
    Livewire::test('dashboard')->assertForbidden();
});

it('developer sees no money cards but sees own work', function () {
    $dev = dbUser('developer');
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $p = Project::create(['client_id' => $c->id, 'name' => 'Mine Proj', 'status' => 'active']);
    $p->members()->attach($dev->id, ['role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);
    Project::create(['client_id' => $c->id, 'name' => 'Hidden Proj', 'status' => 'active']);

    $r = actingAs($dev)->get('/dashboard');
    $r->assertOk();
    $r->assertDontSee('Outstanding')->assertDontSee('Received');
    $r->assertSee('Your projects')->assertSee('Mine Proj')->assertDontSee('Hidden Proj');
});

it('client portal dashboard shows own balance and not other clients', function () {
    $mine = Client::create(['name' => 'Mine Co', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $other = Client::create(['name' => 'Other Co', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $mine->id, 'amount_cents' => 25000, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);
    Project::create(['client_id' => $other->id, 'name' => 'Other Co Secret', 'status' => 'active']);
    $u = User::factory()->create(['client_id' => $mine->id])->assignRole('client');

    $r = actingAs($u)->get('/portal');
    $r->assertOk()->assertSee('Credit $250.00', false)->assertDontSee('Other Co');
});

it('owner sees one revenue chart in the local currency with tooltips', function () {
    $usd = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inr = Client::create(['name' => 'Bharat', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    dbPay($usd, 123400, now()->toDateString(), 10_000_000);
    dbPay($inr, 556600, now()->subDay()->toDateString(), 556600);

    $r = actingAs(dbUser('super_admin'))->get('/dashboard');
    $r->assertOk()->assertSee('Revenue')->assertSee('Payments received, in INR')
        ->assertSee('aria-label="Revenue in INR"', false)->assertDontSee('aria-label="Revenue in USD"', false)
        ->assertSee(now()->format('M j').': ₹100,000.00', false)
        ->assertSee(now()->subDay()->format('M j').': ₹5,566.00', false)
        ->assertDontSee('No payments in this period');
});

it('chart shows an empty state when nothing was received in the period', function () {
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    dbPay($c, 5000, now()->subDays(200)->toDateString(), 400000);

    actingAs(dbUser('super_admin'))->get('/dashboard')->assertOk()
        ->assertSee('No payments in this period')->assertDontSee('aria-label="Revenue in', false);
});

it('chart period switches granularity: 90 days by week, 12 months by month', function () {
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    dbPay($c, 5000, now()->toDateString(), 400000);
    actingAs(dbUser('super_admin'));

    Livewire::test('dashboard')->set('period', 90)->assertSee('by week');
    Livewire::test('dashboard')->set('period', 365)->assertSee('by month, last 12 months')->assertSee(now()->format('M Y').': ₹4,000.00', false);
});

it('developers and clients never see the revenue chart', function (string $role) {
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);

    $u = dbUser($role);
    if ($role === 'client') {
        $u->update(['client_id' => $c->id]);
    }

    actingAs($u)->get($role === 'client' ? '/portal' : '/dashboard')->assertOk()
        ->assertDontSee('Revenue')->assertDontSee('Payments received, in');
})->with(['developer', 'client']);

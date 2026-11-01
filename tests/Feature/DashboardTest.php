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

function dbUser(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

it('owner sees money cards with per-currency amounts', function () {
    $usd = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inr = Client::create(['name' => 'Bharat', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $usd->id, 'amount_cents' => 123400, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);
    Payment::create(['client_id' => $inr->id, 'amount_cents' => 556600, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);

    $r = actingAs(dbUser('super_admin'))->get('/dashboard');
    $r->assertOk();
    $r->assertSee('Received')->assertSee('Outstanding')->assertSee('Active clients')->assertSee('This month');
    $r->assertSee('$1,234.00', false)->assertSee('₹5,566.00', false);
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

it('owner sees a revenue chart per currency with tooltips', function () {
    $usd = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $inr = Client::create(['name' => 'Bharat', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $usd->id, 'amount_cents' => 123400, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);
    Payment::create(['client_id' => $inr->id, 'amount_cents' => 556600, 'paid_on' => now()->subDay()->toDateString(), 'type' => PaymentType::Project]);

    $r = actingAs(dbUser('super_admin'))->get('/dashboard');
    $r->assertOk()->assertSee('Revenue')->assertSee('Payments received, per currency')
        ->assertSee('aria-label="Revenue in USD"', false)->assertSee('aria-label="Revenue in INR"', false)
        ->assertSee(now()->format('M j').': $1,234.00', false)
        ->assertSee(now()->subDay()->format('M j').': ₹5,566.00', false)
        ->assertDontSee('No payments in this period');
});

it('chart shows an empty state when nothing was received in the period', function () {
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'paid_on' => now()->subDays(200)->toDateString(), 'type' => PaymentType::Project]);

    actingAs(dbUser('super_admin'))->get('/dashboard')->assertOk()
        ->assertSee('No payments in this period')->assertDontSee('aria-label="Revenue in', false);
});

it('chart period switches granularity: 90 days by week, 12 months by month', function () {
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);
    actingAs(dbUser('super_admin'));

    Livewire::test('dashboard')->set('period', 90)->assertSee('by week');
    Livewire::test('dashboard')->set('period', 365)->assertSee('by month, last 12 months')->assertSee(now()->format('M Y').': $50.00', false);
});

it('developers and clients never see the revenue chart', function (string $role) {
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'paid_on' => now()->toDateString(), 'type' => PaymentType::Project]);

    $u = dbUser($role);
    if ($role === 'client') {
        $u->update(['client_id' => $c->id]);
    }

    actingAs($u)->get($role === 'client' ? '/portal' : '/dashboard')->assertOk()
        ->assertDontSee('Revenue')->assertDontSee('Payments received, per currency');
})->with(['developer', 'client']);

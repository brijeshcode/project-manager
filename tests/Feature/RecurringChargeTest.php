<?php

use App\Livewire\Billing\Recurring;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function rcClient(): Client
{
    return Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
}

function rcCharge(array $o = []): RecurringCharge
{
    return RecurringCharge::create($o + [
        'client_id' => ($o['client_id'] ?? rcClient()->id), 'description' => 'Hosting', 'amount_cents' => 5000,
        'frequency' => 'monthly', 'next_due_on' => '2026-09-01', 'active' => true,
    ]);
}

function rcOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

it('generates one line and advances, idempotent on rerun', function () {
    $c = rcCharge();
    expect($c->generateDue(Carbon::parse('2026-09-01')))->toBe(1);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2026-10-01');
    expect($c->fresh()->generateDue(Carbon::parse('2026-09-01')))->toBe(0);
    expect(BillableLine::count())->toBe(1);
    $l = BillableLine::first();
    expect([$l->period, $l->amount_cents, $l->client_id, $l->recurring_charge_id])->toBe(['2026-09', 5000, $c->client_id, $c->id]);
});

it('does not duplicate when the unique key already has the period', function () {
    $c = rcCharge();
    BillableLine::create(['client_id' => $c->client_id, 'recurring_charge_id' => $c->id, 'period' => '2026-09', 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 5000, 'amount_cents' => 5000]);
    $c->generateDue(Carbon::parse('2026-09-01'));
    expect(BillableLine::count())->toBe(1);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2026-10-01');
});

it('catches up every missed period once', function () {
    $c = rcCharge();
    expect($c->generateDue(Carbon::parse('2026-12-15')))->toBe(4);
    expect(BillableLine::orderBy('period')->pluck('period')->all())->toBe(['2026-09', '2026-10', '2026-11', '2026-12']);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2027-01-01');
    $c->fresh()->generateDue(Carbon::parse('2026-12-15'));
    expect(BillableLine::count())->toBe(4);
});

it('does not drift month-end days', function () {
    $c = rcCharge(['next_due_on' => '2027-01-31']);
    $c->generateDue(Carbon::parse('2027-03-31'));
    expect(BillableLine::count())->toBe(3);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2027-04-30');
    $c->fresh()->generateDue(Carbon::parse('2027-05-31'));
    expect($c->fresh()->next_due_on->toDateString())->toBe('2027-06-30');
});

it('handles yearly', function () {
    $c = rcCharge(['frequency' => 'yearly', 'next_due_on' => '2024-02-29']);
    $c->generateDue(Carbon::parse('2026-03-01'));
    expect(BillableLine::orderBy('period')->pluck('period')->all())->toBe(['2024', '2025', '2026']);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2027-02-28');
    $c->fresh()->generateDue(Carbon::parse('2028-03-01'));
    expect($c->fresh()->next_due_on->toDateString())->toBe('2029-02-28');
    expect(BillableLine::where('period', '2028')->exists())->toBeTrue();
});

it('skips inactive without advancing', function () {
    $c = rcCharge(['active' => false]);
    expect($c->generateDue(Carbon::parse('2026-12-01')))->toBe(0);
    expect(BillableLine::count())->toBe(0);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2026-09-01');
});

it('carries project_id', function () {
    $client = rcClient();
    $p = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $c = rcCharge(['client_id' => $client->id, 'project_id' => $p->id]);
    $c->generateDue(Carbon::parse('2026-09-01'));
    expect(BillableLine::first()->project_id)->toBe($p->id);
});

it('command generates for all charges and is scheduled', function () {
    Carbon::setTestNow('2026-09-01');
    rcCharge();
    $this->artisan('billing:generate-recurring')->assertExitCode(0);
    expect(BillableLine::count())->toBe(1);
    Carbon::setTestNow();
    $cmds = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');
    expect($cmds)->toContain('billing:generate-recurring');
});

it('is forbidden for non-owners', function () {
    foreach (['project_manager', 'developer', 'client'] as $role) {
        $this->actingAs(User::factory()->create()->assignRole($role))->get(route('billing.recurring'))->assertForbidden();
    }
    $this->actingAs(rcOwner())->get(route('billing.recurring'))->assertOk();
});

it('lets the owner create and toggle charges', function () {
    $client = rcClient();
    Livewire::actingAs(rcOwner())->test(Recurring::class)
        ->set('client_id', $client->id)->set('description', 'Hosting')->set('amount', '49.99')
        ->set('frequency', 'monthly')->set('next_due_on', '2026-11-01')->call('save')->assertHasNoErrors();
    $c = RecurringCharge::first();
    expect([$c->amount_cents, $c->active])->toBe([4999, true]);
    Livewire::actingAs(rcOwner())->test(Recurring::class)->call('toggle', $c->id);
    expect($c->fresh()->active)->toBeFalse();
});

it('rejects a project from another client and bad amounts', function () {
    $client = rcClient();
    $other = rcClient();
    $p = Project::create(['client_id' => $other->id, 'name' => 'X', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    Livewire::actingAs(rcOwner())->test(Recurring::class)
        ->set('client_id', $client->id)->set('project_id', $p->id)->set('description', 'H')->set('amount', '10')
        ->set('frequency', 'monthly')->set('next_due_on', '2026-11-01')->call('save')->assertHasErrors('project_id');
    Livewire::actingAs(rcOwner())->test(Recurring::class)
        ->set('client_id', $client->id)->set('description', 'H')->set('amount', '-5')
        ->set('frequency', 'monthly')->set('next_due_on', '2026-11-01')->call('save')->assertHasErrors('amount');
    expect(RecurringCharge::count())->toBe(0);
});

it('denies mounting the page to non-owners', function () {
    $c = rcCharge();
    Livewire::actingAs(User::factory()->create()->assignRole('project_manager'))->test(Recurring::class)->assertForbidden();
    expect($c->fresh()->active)->toBeTrue();
});

it('rejects malformed, non-positive and huge amounts without erroring', function () {
    $client = rcClient();
    foreach (['1e3', 'abc', '1.2.3', '-5', '0', '0.00', '99999999999999999999'] as $bad) {
        Livewire::actingAs(rcOwner())->test(Recurring::class)
            ->set('client_id', $client->id)->set('description', 'H')->set('amount', $bad)
            ->set('frequency', 'monthly')->set('next_due_on', '2026-11-01')->call('save')->assertHasErrors('amount');
    }
    expect(RecurringCharge::count())->toBe(0);
});

it('rejects a non Y-m-d due date', function () {
    Livewire::actingAs(rcOwner())->test(Recurring::class)
        ->set('client_id', rcClient()->id)->set('description', 'H')->set('amount', '5')
        ->set('frequency', 'monthly')->set('next_due_on', '11/01/2026')->call('save')->assertHasErrors('next_due_on');
});

it('caps catch-up at 36 periods per run and continues next run without duplicates', function () {
    $today = Carbon::parse('2026-10-31');
    $c = rcCharge(['next_due_on' => '2021-10-01']);
    expect($c->generateDue($today))->toBe(36);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2024-10-01');
    expect($c->fresh()->generateDue($today))->toBe(25);
    expect(BillableLine::count())->toBe(61);
    expect(BillableLine::distinct('period')->count('period'))->toBe(61);
    expect($c->fresh()->next_due_on->toDateString())->toBe('2026-11-01');
    expect($c->fresh()->generateDue($today))->toBe(0);
});

it('skips paused periods when resumed', function () {
    Carbon::setTestNow('2026-10-31');
    $c = rcCharge(['next_due_on' => '2026-06-01', 'active' => false]);
    Livewire::actingAs(rcOwner())->test(Recurring::class)->call('toggle', $c->id);
    expect($c->fresh()->active)->toBeTrue();
    expect($c->fresh()->next_due_on->toDateString())->toBe('2026-11-01');
    expect($c->fresh()->generateDue(today()))->toBe(0);
    expect(BillableLine::count())->toBe(0);
    Carbon::setTestNow();
});

it('resume keeps the anchor day and pausing changes nothing else', function () {
    Carbon::setTestNow('2027-02-10');
    $c = rcCharge(['next_due_on' => '2026-10-31', 'active' => false]);
    Livewire::actingAs(rcOwner())->test(Recurring::class)->call('toggle', $c->id)->call('toggle', $c->id);
    expect($c->fresh()->active)->toBeFalse();
    expect($c->fresh()->next_due_on->toDateString())->toBe('2027-02-28');
    Carbon::setTestNow();
});

it('anchors Feb 29 monthly charges', function () {
    $c = rcCharge(['next_due_on' => '2028-01-29']);
    $c->generateDue(Carbon::parse('2028-03-29'));
    expect($c->fresh()->next_due_on->toDateString())->toBe('2028-04-29');
    $d = rcCharge(['next_due_on' => '2028-02-29']);
    $d->generateDue(Carbon::parse('2028-03-29'));
    expect($d->fresh()->next_due_on->toDateString())->toBe('2028-04-29');
    expect(BillableLine::where('recurring_charge_id', $d->id)->pluck('period')->sort()->values()->all())->toBe(['2028-02', '2028-03']);
});

it('save re-authorizes when the user is no longer an owner', function () {
    $client = rcClient();
    $c = rcCharge(['client_id' => $client->id]);
    $t = Livewire::actingAs(rcOwner())->test(Recurring::class);
    $this->actingAs(User::factory()->create()->assignRole('project_manager'));
    $t->set('client_id', $client->id)->set('description', 'H')->set('amount', '5')->set('frequency', 'monthly')->set('next_due_on', '2026-11-01')
        ->call('save')->assertForbidden();
    expect(RecurringCharge::count())->toBe(1);
    expect($c->fresh()->active)->toBeTrue();
});

it('tolerates a tampered client_id on render', function () {
    Livewire::actingAs(rcOwner())->test(Recurring::class)->set('client_id', 99999)->assertOk();
});

it('shows the Billing nav only to owners', function () {
    $this->actingAs(rcOwner())->get(route('dashboard'))->assertSee('Recurring');
    $this->actingAs(User::factory()->create()->assignRole('project_manager'))->get(route('dashboard'))->assertDontSee('Recurring');
});

it('toggle re-authorizes when the user is no longer an owner', function () {
    $c = rcCharge();
    $t = Livewire::actingAs(rcOwner())->test(Recurring::class);
    $this->actingAs(User::factory()->create()->assignRole('project_manager'));
    $t->call('toggle', $c->id)->assertForbidden();
    expect($c->fresh()->active)->toBeTrue();
});

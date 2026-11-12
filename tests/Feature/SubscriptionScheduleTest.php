<?php

use App\Livewire\Billing\RecurringForm;
use App\Livewire\Billing\RecurringShow;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Carbon::setTestNow('2026-11-15 10:00:00');
    $this->client = Client::create(['name' => 'SchedCo', 'currency' => 'USD']);
    $this->owner = User::factory()->create()->assignRole('super_admin');
});

afterEach(fn () => Carbon::setTestNow());

function schedForm(array $set)
{
    $t = Livewire::actingAs(test()->owner)->test(RecurringForm::class)
        ->set('client_id', test()->client->id)->set('description', 'Hosting')->set('amount', '10')->set('frequency', 'monthly');
    foreach ($set as $k => $v) {
        $t->set($k, $v);
    }

    return $t->call('save')->assertHasNoErrors();
}

it('creates an auto-approved service that starts now', function () {
    schedForm(['next_due_on' => '2026-12-01', 'needsApproval' => false]);
    $c = RecurringCharge::first();
    expect($c->active)->toBeTrue()->and($c->client_state)->toBeNull()->and($c->client_approved_at)->not->toBeNull();
    expect($c->statusBadge()[0])->toBe('Active');
});

it('adds past periods as pending lines when asked', function () {
    Livewire::actingAs($this->owner)->test(RecurringForm::class)->set('frequency', 'monthly')
        ->set('next_due_on', '2026-08-01')->set('needsApproval', false)->assertSee('Adds 4 past periods');
    schedForm(['next_due_on' => '2026-08-01', 'needsApproval' => false, 'addPast' => true]);
    $c = RecurringCharge::first();
    expect(BillableLine::where('recurring_charge_id', $c->id)->pluck('period')->sort()->values()->all())->toBe(['2026-08', '2026-09', '2026-10', '2026-11'])
        ->and($c->next_due_on->toDateString())->toBe('2026-12-01');
});

it('skips past periods without billing when not asked', function () {
    schedForm(['next_due_on' => '2026-08-01', 'needsApproval' => false, 'addPast' => false]);
    $c = RecurringCharge::first();
    expect(BillableLine::count())->toBe(0)->and($c->next_due_on->gte(today()))->toBeTrue();
});

it('skips periods without billing and records them', function () {
    $c = RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Hosting', 'amount_cents' => 1000, 'frequency' => 'monthly', 'next_due_on' => '2026-12-01', 'active' => true]);
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->set('skipCount', 2)->call('skip')->assertHasNoErrors()
        ->assertSeeInOrder(['Jan 2027', 'Skipped', 'Dec 2026', 'Skipped']);
    $c->refresh();
    expect($c->next_due_on->toDateString())->toBe('2027-02-01')->and($c->skipped_periods)->toBe(['2026-12', '2027-01'])->and(BillableLine::count())->toBe(0);
});

it('pauses until a date, then resumes there without billing the paused periods', function () {
    $c = RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Hosting', 'amount_cents' => 1000, 'frequency' => 'monthly', 'next_due_on' => '2026-12-01', 'active' => true]);
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->set('resumeOn', '2026-11-10')->call('pauseUntil')->assertHasErrors('resumeOn')
        ->set('resumeOn', '2027-02-15')->call('pauseUntil')->assertHasNoErrors();
    expect($c->fresh()->statusBadge())->toBe(['Paused until Feb 15, 2027', 'amber']);

    Carbon::setTestNow('2027-01-20');
    $this->artisan('billing:generate-recurring')->assertSuccessful();
    expect($c->fresh()->active)->toBeFalse()->and(BillableLine::count())->toBe(0);

    Carbon::setTestNow('2027-02-15');
    $this->artisan('billing:generate-recurring')->assertSuccessful();
    $c->refresh();
    expect($c->active)->toBeTrue()->and($c->resume_on)->toBeNull()->and($c->next_due_on->toDateString())->toBe('2027-03-01')->and(BillableLine::count())->toBe(0);
});

<?php

use App\Livewire\Billing\Recurring;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $this->make = fn (string $d, array $o = []) => RecurringCharge::create($o + [
        'client_id' => $this->client->id, 'description' => $d, 'amount_cents' => 5000,
        'frequency' => 'monthly', 'next_due_on' => today()->addDays(5)->toDateString(), 'active' => true,
    ]);
});

it('lists active first, then stopped ones most recently stopped first', function () {
    $old = ($this->make)('Old stop', ['active' => false, 'client_state' => RecurringCharge::ENDED, 'ended_at' => now()->subYear()]);
    $act = ($this->make)('Running');
    $recent = ($this->make)('Recent pause', ['active' => false]);
    $old->forceFill(['updated_at' => now()->subYear()])->saveQuietly();

    Livewire::test(Recurring::class)->assertSeeInOrder(['Running', 'Recent pause', 'Old stop']);
});

it('bulk skips, pauses and resumes, leaving ineligible ones alone', function () {
    $a = ($this->make)('A');
    $b = ($this->make)('B');
    $ended = ($this->make)('C', ['active' => false, 'client_state' => RecurringCharge::ENDED, 'ended_at' => now()]);
    $due = $a->next_due_on->copy();

    Livewire::test(Recurring::class)
        ->set('selected', [(string) $a->id, (string) $b->id, (string) $ended->id])
        ->call('bulk', 'skip')->assertDispatched('toast', message: '2 of 3 services skipped a period.')
        ->assertSet('selected', [])
        ->set('selected', [(string) $a->id, (string) $ended->id])
        ->call('bulk', 'pause')->assertDispatched('toast', message: '1 of 2 services paused.')
        ->set('selected', [(string) $a->id, (string) $ended->id])
        ->call('bulk', 'resume')->assertDispatched('toast', message: '1 of 2 services resumed.');

    expect($b->fresh()->next_due_on->toDateString())->toBe($due->addMonth()->toDateString())
        ->and($a->fresh()->active)->toBeTrue()
        ->and($ended->fresh()->active)->toBeFalse();
});

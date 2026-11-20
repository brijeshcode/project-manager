<?php

use App\Livewire\Billing\RecurringForm;
use App\Livewire\Billing\RecurringShow;
use App\Livewire\Portal\SubscriptionShow;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->client = Client::create(['name' => 'SubCo', 'currency' => 'USD', 'portal_features' => array_keys(Client::PORTAL_FEATURES)]);
    $this->clientUser = User::factory()->create(['client_id' => $this->client->id])->assignRole('client');
    $this->owner = User::factory()->create()->assignRole('super_admin');
});

it('a new subscription waits for the client, who approves it with a note', function () {
    Livewire::actingAs($this->owner)->test(RecurringForm::class)
        ->set('client_id', $this->client->id)->set('description', 'Hosting')->set('amount', '20')
        ->set('frequency', 'monthly')->set('next_due_on', today()->toDateString())->call('save')->assertHasNoErrors();
    $c = RecurringCharge::first();
    expect($c->active)->toBeFalse()->and($c->client_state)->toBe('awaiting');
    expect($c->generateDue(today()))->toBe(0);

    $page = Livewire::actingAs($this->clientUser)->test(SubscriptionShow::class, ['charge' => $c->id])
        ->assertSee('Approve this subscription')->call('approve')->assertHasErrors('note');
    $page->set('note', 'Go ahead')->call('approve')->assertHasNoErrors();
    $c->refresh();
    expect($c->active)->toBeTrue()->and($c->client_state)->toBeNull()->and($c->client_note)->toBe('Go ahead');
});

it('the client requests to unsubscribe with a note and the team approves or rejects', function () {
    $c = RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Support', 'amount_cents' => 5000, 'frequency' => 'monthly', 'next_due_on' => today(), 'active' => true]);
    Livewire::actingAs($this->clientUser)->test(SubscriptionShow::class, ['charge' => $c->id])
        ->set('note', 'No longer needed')->call('requestCancel')->assertHasNoErrors();
    expect($c->fresh()->client_state)->toBe('cancel_requested')->and($c->fresh()->active)->toBeTrue();

    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->assertSee('No longer needed')->call('decideCancel', false);
    expect($c->fresh()->client_state)->toBeNull();

    $c->requestCancel('Really stop');
    Livewire::actingAs($this->owner)->test(RecurringShow::class, ['charge' => $c])->call('decideCancel', true);
    $c->refresh();
    expect($c->active)->toBeFalse()->and($c->client_state)->toBe('ended')->and($c->statusBadge()[0])->toBe('Ended');
});

it('shows how often a subscription was invoiced and keeps other clients out', function () {
    $c = RecurringCharge::create(['client_id' => $this->client->id, 'description' => 'Hosting', 'amount_cents' => 2000, 'frequency' => 'monthly', 'next_due_on' => today()->subMonths(2), 'active' => true]);
    $c->generateDue(today());
    $lines = BillableLine::where('recurring_charge_id', $c->id)->pluck('id')->all();
    app(InvoiceService::class)->create($this->client, $lines)->update(['status' => 'sent']);

    $this->actingAs($this->clientUser)->get(route('portal.subscriptions'))->assertOk()->assertSee(count($lines).' times');
    $this->actingAs($this->clientUser)->get(route('portal.subscription', $c->id))->assertOk()->assertSee('Invoice history');

    $other = User::factory()->create(['client_id' => Client::create(['name' => 'Other', 'currency' => 'USD'])->id])->assignRole('client');
    $this->actingAs($other)->get(route('portal.subscription', $c->id))->assertNotFound();
});

<?php

use App\Livewire\Billing\RecurringForm;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\ClientAccount;
use App\Services\InvoiceService;
use App\Services\ProjectEconomics;
use App\Support\DashboardMoney;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('pass-through costs stay in the client balance but come off income', function () {
    $client = Client::create(['name' => 'PT Co', 'currency' => 'INR']); // local currency: rate 1, no conversion
    $project = Project::create(['client_id' => $client->id, 'name' => 'PT Proj', 'status' => 'active']);
    $work = BillableLine::create(['client_id' => $client->id, 'project_id' => $project->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => 10000, 'amount_cents' => 10000]);
    $invoice = app(InvoiceService::class)->create($client, [$work->id], [
        ['description' => 'Server', 'quantity' => '1', 'unit_price' => '25', 'pass_through' => true],
    ]);
    $invoice->update(['status' => 'sent']);
    BillableLine::where('description', 'Server')->update(['project_id' => $project->id]);
    Payment::create(['client_id' => $client->id, 'project_id' => $project->id, 'amount_cents' => 12500, 'paid_on' => today(), 'type' => 'project',
        'local_amount_cents' => 12500, 'local_currency' => 'INR', 'exchange_rate' => '1']);

    expect(BillableLine::where('description', 'Server')->value('pass_through'))->toBeTrue()
        ->and($invoice->fresh()->total_cents)->toBe(12500)
        ->and(app(ClientAccount::class)->balanceCents($client))->toBe(0); // balance unchanged: the client pays it

    $money = DashboardMoney::forPeriodLocal(today()->subDays(29), now());
    expect($money['received'])->toBe(10000)->and($money['pass_through'])->toBe(2500)->and($money['this_month'])->toBe(10000);
    expect(array_sum(array_column(DashboardMoney::revenueSeriesLocal(today()->subDays(29), now()), 'cents')))->toBe(10000);
    expect(DashboardMoney::topClients(today()->subDays(29), now())[0]['revenue'])->toBe(10000);

    $e = ProjectEconomics::for($project);
    expect($e['received_cents'])->toBe(12500)->and($e['pass_through_cents'])->toBe(2500)->and($e['profit_cents'])->toBe(10000);
});

it('a pass-through subscription passes the flag to its lines and leaves monthly recurring', function () {
    $owner = User::factory()->create()->assignRole('super_admin');
    $client = Client::create(['name' => 'PT Co', 'currency' => 'INR']);
    Livewire::actingAs($owner)->test(RecurringForm::class)
        ->set('client_id', $client->id)->set('description', 'Server')->set('amount', '25')->set('frequency', 'monthly')
        ->set('next_due_on', today()->toDateString())->set('needsApproval', false)->set('pass_through', true)
        ->call('save')->assertHasNoErrors();
    $c = RecurringCharge::first();
    expect($c->pass_through)->toBeTrue()->and($c->lines()->first()?->pass_through ?? BillableLine::first()->pass_through)->toBeTrue();

    $this->actingAs($owner)->get(route('billing.recurring'))->assertOk()->assertSee('Pass-through');
    $this->actingAs($owner)->get(route('billing.recurring.show', $c))->assertSeeHtml('data-test="pass-through-badge"');
    expect(Livewire::actingAs($owner)->test(\App\Livewire\Billing\Recurring::class)->viewData('stats')['monthly'])->toBe([]);
});

it('admin invoice page tags pass-through lines but the client-facing body does not', function () {
    $owner = User::factory()->create()->assignRole('super_admin');
    $client = Client::create(['name' => 'PT Co', 'currency' => 'INR']);
    $invoice = app(InvoiceService::class)->create($client, [], [
        ['description' => 'Server hosting', 'quantity' => '1', 'unit_price' => '25', 'pass_through' => true],
    ]);
    $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertSeeHtml('data-test="pass-through-lines"');
    expect(view('invoices._body', ['invoice' => $invoice->fresh()])->render())->not->toContain('Pass-through');
});

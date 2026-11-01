<?php

use App\Enums\CostRateType;
use App\Livewire\Projects\Economics;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ProjectEconomics;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ecoOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function ecoProject(?Client $c = null): Project
{
    $c ??= Client::create(['name' => 'EcoCo', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $p = Project::create(['client_id' => $c->id, 'name' => 'EcoProj', 'status' => 'active', 'buffer_percent' => 0]);
    Module::create(['project_id' => $p->id, 'name' => 'M', 'position' => 1]);

    return $p;
}

function ecoTask(Project $p): Task
{
    return Task::create(['module_id' => $p->modules()->first()->id, 'creator_id' => ecoOwner()->id, 'title' => 'T',
        'status' => 'published', 'scope' => 'in', 'board_column' => 'Backlog', 'position' => 1]);
}

function ecoDev(CostRateType $type, float $amount): User
{
    $u = User::factory()->create(['cost_rate_type' => $type, 'cost_rate_amount' => $amount]);

    return $u->assignRole('developer');
}

function ecoLog($timeable, User $u, float $h): void
{
    TimeEntry::create(['timeable_type' => $timeable::class, 'timeable_id' => $timeable->id, 'user_id' => $u->id,
        'hours' => $h, 'worked_on' => today()->toDateString()]);
}

function ecoInvoice(Project $p, int $cents, ?int $projectId = null, string $status = 'sent'): Invoice
{
    $l = BillableLine::create(['client_id' => $p->client_id, 'project_id' => $projectId ?? $p->id, 'description' => 'W',
        'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
    $inv = app(InvoiceService::class)->create($p->client, [$l->id]);
    $inv->forceFill(['status' => $status])->save();

    return $inv;
}

function ecoPay(Project $p, int $cents, ?int $projectId = null, ?int $clientId = null): void
{
    Payment::create(['client_id' => $clientId ?? $p->client_id, 'project_id' => $projectId ?? $p->id,
        'amount_cents' => $cents, 'paid_on' => today()->toDateString(), 'type' => 'project']);
}

it('costs an hourly user hours x rate', function () {
    $p = ecoProject();
    ecoLog(ecoTask($p), ecoDev(CostRateType::Hourly, 60), 10);
    $e = ProjectEconomics::for($p);
    expect($e['cost_cents'])->toBe(60000)->and($e['hours'])->toBe(10.0)->and($e['currency'])->toBe('USD');
});

it('counts a flat user once across tasks and subtasks, and each flat user once', function () {
    $p = ecoProject();
    $t = ecoTask($p);
    $s = Subtask::create(['task_id' => $t->id, 'title' => 's']);
    $a = ecoDev(CostRateType::Flat, 500);
    $b = ecoDev(CostRateType::Flat, 200);
    ecoLog($t, $a, 1);
    ecoLog($t, $a, 2);
    ecoLog($s, $a, 3);
    expect(ProjectEconomics::for($p)['cost_cents'])->toBe(50000);
    ecoLog($s, $b, 1);
    expect(ProjectEconomics::for(Project::find($p->id))['cost_cents'])->toBe(70000);
});

it('mixes hourly and flat', function () {
    $p = ecoProject();
    $t = ecoTask($p);
    ecoLog($t, ecoDev(CostRateType::Hourly, 60), 2);
    ecoLog($t, ecoDev(CostRateType::Flat, 500), 1);
    expect(ProjectEconomics::for($p)['cost_cents'])->toBe(12000 + 50000);
});

it('excludes void invoices from invoiced', function () {
    $p = ecoProject();
    ecoInvoice($p, 100000);
    ecoInvoice($p, 5000, null, 'void');
    expect(ProjectEconomics::for($p)['invoiced_cents'])->toBe(100000);
});

it('excludes payments whose client does not match the project client', function () {
    $p = ecoProject();
    $other = Client::create(['name' => 'Other', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    ecoPay($p, 50000);
    ecoPay($p, 9999, null, $other->id);
    expect(ProjectEconomics::for($p)['received_cents'])->toBe(50000);
});

it('computes profit and hourly figures', function () {
    $p = ecoProject();
    ecoLog(ecoTask($p), ecoDev(CostRateType::Hourly, 10), 4);
    ecoInvoice($p, 100000);
    ecoPay($p, 50000);
    $e = ProjectEconomics::for($p);
    expect($e['profit_cents'])->toBe(50000 - 4000)
        ->and($e['hourly_received_cents'])->toBe(12500)
        ->and($e['hourly_invoiced_cents'])->toBe(25000);
});

it('reports negative profit in red', function () {
    $p = ecoProject();
    ecoLog(ecoTask($p), ecoDev(CostRateType::Hourly, 100), 10);
    ecoPay($p, 10000);
    expect(ProjectEconomics::for($p)['profit_cents'])->toBe(-90000);
    $this->actingAs(ecoOwner());
    Livewire::test(Economics::class, ['project' => $p])->assertSee('-$900.00')->assertSeeHtml('text-red-600');
});

it('returns null hourly at zero hours and renders', function () {
    $p = ecoProject();
    ecoPay($p, 1000);
    $e = ProjectEconomics::for($p);
    expect($e['hourly_received_cents'])->toBeNull()->and($e['hourly_invoiced_cents'])->toBeNull();
    $this->actingAs(ecoOwner());
    Livewire::test(Economics::class, ['project' => $p])->assertOk()->assertSee('—');
});

it('is exact with decimal hours', function () {
    $p = ecoProject();
    $t = ecoTask($p);
    $u = ecoDev(CostRateType::Hourly, 60);
    foreach (range(1, 3) as $i) {
        ecoLog($t, $u, 0.25);
    }
    $e = ProjectEconomics::for($p);
    expect($e['hours'])->toBe(0.75)->and($e['cost_cents'])->toBe(4500);
});

it('lets the owner view the page and sees the project link', function () {
    $p = ecoProject();
    $this->actingAs(ecoOwner());
    $this->get(route('projects.economics', $p))->assertOk()->assertSee('Hours logged');
    $this->get(route('projects.show', $p))->assertSee('Economics');
});

it('forbids non-owners and hides the link and cost rates', function (string $role) {
    $p = ecoProject();
    ecoLog(ecoTask($p), ecoDev(CostRateType::Hourly, 77.77), 1);
    $u = User::factory()->create(['client_id' => $role === 'client' ? $p->client_id : null])->assignRole($role);
    $this->actingAs($u);
    $this->get(route('projects.economics', $p))->assertForbidden();
    if ($role !== 'client') {
        $this->get(route('projects.show', $p))->assertDontSee('Economics')->assertDontSee('77.77');
    }
})->with(['project_manager', 'developer', 'client']);

it('excludes other projects entries, payments and unattributed or other-project invoice lines', function () {
    $p = ecoProject();
    $o = ecoProject($p->client);
    ecoLog(ecoTask($o), ecoDev(CostRateType::Hourly, 60), 5);
    ecoPay($o, 8000);
    ecoInvoice($p, 1000);
    ecoInvoice($o, 7000); // sent, other project
    $l = BillableLine::create(['client_id' => $p->client_id, 'description' => 'N', 'quantity' => 1, 'unit_price_cents' => 900, 'amount_cents' => 900]);
    $inv = app(InvoiceService::class)->create($p->client, [$l->id]);
    expect($inv->status)->toBe('sent');
    $e = ProjectEconomics::for($p);
    expect($e['hours'])->toBe(0.0)->and($e['cost_cents'])->toBe(0)->and($e['received_cents'])->toBe(0)->and($e['invoiced_cents'])->toBe(1000);
});

it('excludes a void invoice line even for this project', function () {
    $p = ecoProject();
    ecoInvoice($p, 4000, null, 'void');
    expect(ProjectEconomics::for($p)['invoiced_cents'])->toBe(0);
});

it('costs users with null rate type or null amount as zero', function () {
    $p = ecoProject();
    $t = ecoTask($p);
    ecoLog($t, User::factory()->create(['cost_rate_type' => null, 'cost_rate_amount' => 50]), 3);
    ecoLog($t, User::factory()->create(['cost_rate_type' => CostRateType::Hourly, 'cost_rate_amount' => null]), 3);
    expect(ProjectEconomics::for($p)['cost_cents'])->toBe(0);
});

it('rounds hourly cost half-up: 0.25h x 33.33 = 833 cents', function () {
    $p = ecoProject();
    ecoLog(ecoTask($p), ecoDev(CostRateType::Hourly, 33.33), 0.25);
    expect(ProjectEconomics::for($p)['cost_cents'])->toBe(833);
});

it('counts entries on draft tasks like estimateVsActual', function () {
    $p = ecoProject();
    $t = ecoTask($p);
    $t->update(['status' => 'draft']);
    ecoLog($t, ecoDev(CostRateType::Hourly, 10), 2);
    expect(ProjectEconomics::for($p)['cost_cents'])->toBe(2000)->and($p->estimateVsActual()['logged'])->toBe(2.0);
});

it('links to the economics href for the owner only, including clients', function () {
    $p = ecoProject();
    $href = 'projects/'.$p->id.'/economics';
    $this->actingAs(ecoOwner());
    $this->get(route('projects.show', $p))->assertSee($href, false);
    foreach (['project_manager', 'client'] as $role) {
        $u = User::factory()->create(['client_id' => $role === 'client' ? $p->client_id : null])->assignRole($role);
        $this->actingAs($u);
        $r = $this->get(route('projects.show', $p));
        if ($r->status() === 200) {
            $r->assertDontSee($href, false)->assertDontSee('Economics');
        }
    }
});

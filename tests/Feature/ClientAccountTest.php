<?php

use App\Enums\PaymentType;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Services\ClientAccount;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function caProject(int $budget = 100000): Project
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $p = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    Module::create(['project_id' => $p->id, 'name' => 'M1', 'position' => 1, 'budget_amount_cents' => $budget]);
    Module::create(['project_id' => $p->id, 'name' => 'M2 (no budget)', 'position' => 2]);

    return $p;
}

function caLine(Client $c, array $o = []): BillableLine
{
    return BillableLine::create($o + ['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => 1000, 'amount_cents' => 1000]);
}

function caPay(Client $c, int $cents, ?int $projectId = null): Payment
{
    return Payment::create(['client_id' => $c->id, 'project_id' => $projectId, 'amount_cents' => $cents, 'paid_on' => '2026-11-01', 'type' => PaymentType::Project]);
}

it('pending scope returns only uninvoiced lines', function () {
    $c = caProject()->client;
    $a = caLine($c);
    caLine($c, ['invoice_id' => 99]);
    expect(BillableLine::pending()->pluck('id')->all())->toBe([$a->id]);
});

it('rejects duplicate recurring charge periods but allows many null pairs', function () {
    $c = caProject()->client;
    caLine($c, ['recurring_charge_id' => 1, 'period' => '2026-11']);
    expect(fn () => caLine($c, ['recurring_charge_id' => 1, 'period' => '2026-11']))->toThrow(QueryException::class);
    caLine($c, ['recurring_charge_id' => 1, 'period' => '2026-12']);
    caLine($c);
    caLine($c);
    expect(BillableLine::count())->toBe(4);
});

it('balance is payments received when nothing is invoiced', function () {
    $c = caProject()->client;
    caPay($c, 30000);
    expect(app(ClientAccount::class)->balanceCents($c))->toBe(30000);
});

it('soft balance is budget minus project payments', function () {
    $p = caProject(100000);
    caPay($p->client, 30000, $p->id);
    caPay($p->client, 5000); // not tied to the project
    expect(app(ClientAccount::class)->softBalanceCents($p))->toBe(70000);
});

it('soft balance never goes negative', function () {
    $p = caProject(1000);
    caPay($p->client, 5000, $p->id);
    expect(app(ClientAccount::class)->softBalanceCents($p))->toBe(0);
});

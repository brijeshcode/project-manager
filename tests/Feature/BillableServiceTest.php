<?php

use App\Enums\TaskStatus;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\BillableService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function bsModule(string $mode = 'fixed', ?int $rate = null): Module
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => $mode]);
    $p = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);

    return Module::create(['project_id' => $p->id, 'name' => 'M', 'position' => 1, 'hourly_rate_cents' => $rate]);
}

function bsTask(Module $m, array $o = []): Task
{
    return Task::create($o + [
        'module_id' => $m->id, 'creator_id' => User::factory()->create()->id, 'title' => 'Build it',
        'status' => TaskStatus::Published, 'board_column' => 'Done',
    ]);
}

function bsSync(Module $m): void
{
    app(BillableService::class)->syncCompletedWork($m->project->client);
}

it('creates one pending line for a done priced task, idempotently', function () {
    $m = bsModule();
    $t = bsTask($m, ['price' => 200]);
    bsSync($m);
    bsSync($m);
    $lines = app(BillableService::class)->pendingFor($m->project->client);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->amount_cents)->toBe(20000)
        ->and($lines[0]->unit_price_cents)->toBe(20000)
        ->and($lines[0]->description)->toBe('Build it')
        ->and($lines[0]->source_id)->toBe($t->id)
        ->and($lines[0]->project_id)->toBe($m->project_id);
});

it('prices unpriced tasks by hours x rate for hourly clients', function () {
    $m = bsModule('hourly', 5000);
    bsTask($m, ['estimate_hours' => 2]);
    bsSync($m);
    expect(BillableLine::first()->amount_cents)->toBe(10000);
});

it('does not bill unpriced tasks for fixed clients', function () {
    $m = bsModule();
    bsTask($m, ['estimate_hours' => 2]);
    bsSync($m);
    expect(BillableLine::count())->toBe(0);
});

it('updates an uninvoiced line when the task price changes', function () {
    $m = bsModule();
    $t = bsTask($m, ['price' => 200]);
    bsSync($m);
    $t->update(['price' => 250, 'title' => 'Renamed']);
    bsSync($m);
    expect(BillableLine::count())->toBe(1)
        ->and(BillableLine::first()->amount_cents)->toBe(25000)
        ->and(BillableLine::first()->description)->toBe('Renamed');
});

it('removes an uninvoiced line when the task leaves Done but keeps invoiced ones', function () {
    $m = bsModule();
    $a = bsTask($m, ['price' => 200]);
    $b = bsTask($m, ['price' => 100, 'title' => 'B']);
    bsSync($m);
    BillableLine::where('source_id', $b->id)->update(['invoice_id' => 7]);
    $a->update(['board_column' => 'In Progress']);
    $b->update(['board_column' => 'In Progress', 'price' => 999]);
    bsSync($m);
    expect(BillableLine::pluck('source_id')->all())->toBe([$b->id])
        ->and(BillableLine::first()->amount_cents)->toBe(10000);
});

it('ignores drafts and other clients', function () {
    $m = bsModule();
    bsTask($m, ['price' => 200, 'status' => TaskStatus::Draft]);
    $other = bsModule();
    bsTask($other, ['price' => 300]);
    bsSync($m);
    expect(BillableLine::count())->toBe(0);
});

<?php

use App\Enums\TaskStatus;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
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

    return Module::create(['project_id' => $p->id, 'name' => 'M', 'position' => 1, 'hourly_rate' => $rate === null ? null : number_format($rate / 100, 2, '.', '')]);
}

function bsTask(Module $m, array $o = []): Task
{
    return Task::create($o + [
        'module_id' => $m->id, 'creator_id' => User::factory()->create()->id, 'title' => 'Build it',
        'status' => TaskStatus::Published, 'board_column_id' => colId($m, 'Done'),
    ]);
}

function bsSync(Module $m): void
{
    app(BillableService::class)->syncCompletedWork($m->project->client);
}

it('creates one pending line for a done priced task, idempotently', function () {
    $m = bsModule();
    $t = bsTask($m, ['budget_amount' => 200]);
    bsSync($m);
    bsSync($m);
    $lines = app(BillableService::class)->pendingFor($m->project->client);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->amount_cents)->toBe(20000)
        ->and($lines[0]->unit_price_cents)->toBe(20000)
        ->and($lines[0]->description)->toBe('SIT-1 Build it')
        ->and($lines[0]->source_id)->toBe($t->id)
        ->and($lines[0]->project_id)->toBe($m->project_id);
});

function bsLog(Task $t, float $h): void
{
    TimeEntry::create(['timeable_type' => Task::class, 'timeable_id' => $t->id, 'user_id' => User::factory()->create()->id, 'hours' => $h, 'worked_on' => today()]);
}

it('an hourly task bills its value: logged hours x rate', function () {
    $m = bsModule('hourly', 5000);
    $t = bsTask($m, ['estimate_hours' => 9]);
    bsLog($t, 2);
    bsSync($m);
    expect(BillableLine::first()->amount_cents)->toBe(10000);
    bsLog($t, 1); // more time: the pending line follows the value
    bsSync($m);
    expect(BillableLine::first()->amount_cents)->toBe(15000);
});

it('does not bill unpriced tasks for fixed clients', function () {
    $m = bsModule();
    bsTask($m, ['estimate_hours' => 2]);
    bsSync($m);
    expect(BillableLine::count())->toBe(0);
});

it('updates an uninvoiced line when the task budget amount changes', function () {
    $m = bsModule();
    $t = bsTask($m, ['budget_amount' => 200]);
    bsSync($m);
    $t->update(['budget_amount' => 250, 'title' => 'Renamed']);
    bsSync($m);
    expect(BillableLine::count())->toBe(1)
        ->and(BillableLine::first()->amount_cents)->toBe(25000)
        ->and(BillableLine::first()->description)->toBe('SIT-1 Renamed');
});

it('removes an uninvoiced line when the task leaves Done but keeps invoiced ones', function () {
    $m = bsModule();
    $a = bsTask($m, ['budget_amount' => 200]);
    $b = bsTask($m, ['budget_amount' => 100, 'title' => 'B']);
    bsSync($m);
    BillableLine::where('source_id', $b->id)->update(['invoice_id' => 7]);
    $a->update(['board_column_id' => colId($m, 'In progress')]);
    $b->update(['board_column_id' => colId($m, 'In progress'), 'budget_amount' => 999]);
    bsSync($m);
    expect(BillableLine::pluck('source_id')->all())->toBe([$b->id])
        ->and(BillableLine::first()->amount_cents)->toBe(10000);
});

it('ignores drafts and other clients', function () {
    $m = bsModule();
    bsTask($m, ['budget_amount' => 200, 'status' => TaskStatus::Draft]);
    $other = bsModule();
    bsTask($other, ['budget_amount' => 300]);
    bsSync($m);
    expect(BillableLine::count())->toBe(0);
});

it('hourly billing uses the adjusted billed hours', function () {
    $m = bsModule('hourly', 4500);
    $t = bsTask($m);
    bsLog($t, 4);
    $t->update(['billable_hours' => 1.5]);
    bsSync($m);
    expect(BillableLine::first()->amount_cents)->toBe(6750);
});

it('treats a value of 0 as unpriced: nothing is billed', function () {
    $h = bsModule('hourly', 5000);
    bsTask($h, ['estimate_hours' => 2]); // no time logged: 0
    bsSync($h);
    expect(BillableLine::count())->toBe(0);

    $f = bsModule();
    bsTask($f, ['budget_amount' => 0, 'estimate_hours' => 2]);
    bsSync($f);
    expect(BillableLine::where('client_id', $f->project->client_id)->count())->toBe(0);
});

it('a module mode switch never touches invoiced lines and keeps the stored values', function () {
    $m = bsModule('hourly', 5000);
    $a = bsTask($m, ['estimate_hours' => 2]);
    $b = bsTask($m, ['estimate_hours' => 1, 'title' => 'B']);
    bsLog($a, 2);
    bsLog($b, 1);
    bsSync($m);
    BillableLine::where('source_id', $b->id)->update(['invoice_id' => 3]);
    $m->updateBudget(['billing_mode' => 'fixed'], User::factory()->create(), 'Now fixed');
    bsSync($m);
    expect(BillableLine::where('source_id', $a->id)->value('amount_cents'))->toBe(10000)->and(BillableLine::where('source_id', $b->id)->value('invoice_id'))->toBe(3);
    bsLog($a, 5); // fixed now: the value no longer follows time
    bsSync($m);
    expect(BillableLine::where('source_id', $a->id)->value('amount_cents'))->toBe(10000);
});

it('deletes an uninvoiced line when the task amount becomes null', function () {
    $m = bsModule();
    $t = bsTask($m, ['budget_amount' => 200]);
    bsSync($m);
    $t->update(['budget_amount' => null]);
    bsSync($m);
    expect(BillableLine::count())->toBe(0);
});

it('never alters another client\'s lines', function () {
    $a = bsModule();
    $b = bsModule();
    $ta = bsTask($a, ['budget_amount' => 100]);
    $tb = bsTask($b, ['budget_amount' => 300]);
    bsSync($b);
    bsSync($a);
    $tb->update(['board_column_id' => colId($b, 'Backlog')]);
    bsSync($a);
    expect(BillableLine::where('client_id', $b->project->client_id)->pluck('amount_cents')->all())->toBe([30000])
        ->and(BillableLine::where('client_id', $a->project->client_id)->count())->toBe(1);
});

it('does not bill hourly tasks when the module has no rate', function () {
    $m = bsModule('hourly', null);
    bsTask($m, ['estimate_hours' => 2]);
    bsSync($m);
    expect(BillableLine::count())->toBe(0);
});

it('self-heals duplicate uninvoiced lines', function () {
    $m = bsModule();
    $t = bsTask($m, ['budget_amount' => 200]);
    bsSync($m);
    $dup = BillableLine::first()->replicate();
    $dup->save();
    $inv = BillableLine::first()->replicate();
    $inv->invoice_id = 5;
    $inv->save();
    expect(BillableLine::count())->toBe(3);
    bsSync($m);
    expect(BillableLine::whereNull('invoice_id')->count())->toBe(1)
        ->and(BillableLine::whereNotNull('invoice_id')->count())->toBe(1);
});

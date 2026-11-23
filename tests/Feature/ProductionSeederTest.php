<?php

use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Project;
use App\Models\RecurringCharge;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(\Database\Seeders\RoleSeeder::class));

it('seeds the production clients once, with no billed lines', function () {
    $seed = function () {
        $s = app(ProductionSeeder::class);
        $s->historyPath = 'missing.json'; // clients, projects and hard-coded subscriptions only
        $s->run();
    };
    $seed();
    $seed(); // idempotent

    expect(Client::count())->toBe(6)->and(Project::count())->toBe(8)->and(RecurringCharge::count())->toBe(0)
        ->and(\App\Models\Payment::count())->toBe(0)->and(\App\Models\BankAccount::count())->toBe(1)
        ->and(BillableLine::count())->toBe(0);

    $david = Client::where('name', 'David Zouein')->first();
    expect($david->currency)->toBe('USD')->and($david->country)->toBe('Morocco');
    $login = \App\Models\User::where('email', 'david@example.com')->first();
    expect($login->client_id)->toBe($david->id)->and($login->hasRole('client'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Hash::check('password', $login->password))->toBeTrue()
        ->and(\App\Models\User::whereNotNull('client_id')->count())->toBe(3);
    $app = Project::where('name', 'Luigi Darbur App')->first();
    expect((float) $app->final_budget)->toBe(2300.0)->and($app->status)->toBe('completed')->and($app->completed_at->toDateString())->toBe('2025-11-30');

});

it('seeds each project\'s modules with done tasks, and the old currency todos as drafts', function () {
    \App\Models\User::factory()->create();
    $seed = function () {
        $s = app(ProductionSeeder::class);
        $s->historyPath = 'missing.json';
        $s->run();
    };
    $seed();
    $seed();

    $wts = Project::where('name', 'Wholesale Trading System')->first();
    $gz = Project::where('client_id', Client::where('name', 'Nick Zouein')->value('id'))->first();
    expect($wts->modules()->count())->toBe(15)->and($gz->modules()->count())->toBe(15)
        ->and(\App\Models\Task::where('project_id', $wts->id)->count())->toBe(89)
        ->and(\App\Models\Task::where('project_id', $gz->id)->count())->toBe(89)
        ->and($wts->modules()->firstWhere('name', 'Customers')->derivedStatus())->toBe('completed')
        ->and($gz->modules()->firstWhere('name', 'Customers')->derivedStatus())->toBe('completed')
        ->and($gz->status)->toBe('completed')
        ->and(\App\Models\Task::where('status', 'published')->where(fn ($q) => $q->whereNull('client_approval')->orWhere('client_approval', '!=', 'approved'))->count())->toBe(0);

    $cts = Project::where('name', 'Currency Trading System')->first();
    $pending = $cts->modules()->firstWhere('name', 'Pending tasks');
    expect($cts->modules()->count())->toBe(12)->and($pending->position)->toBe(12)
        ->and(\App\Models\Task::where('project_id', $cts->id)->where('status', 'published')->count())->toBe(38)
        ->and($pending->tasks()->where('status', 'draft')->where('client_visible', false)->count())->toBe(17)
        ->and($pending->tasks()->first()->column->stage)->toBe('not_started');

    $luigi = Project::where('name', 'Luigi Darbur App')->first();
    expect($luigi->modules()->count())->toBe(17)->and(\App\Models\Task::where('project_id', $luigi->id)->count())->toBe(73);

    $accounts = Project::where('name', 'Accounts System')->first();
    expect($accounts->modules()->count())->toBe(13)->and(\App\Models\Task::where('project_id', $accounts->id)->count())->toBe(48);
});

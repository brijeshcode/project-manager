<?php

use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Project;
use App\Models\RecurringCharge;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the production clients once, with no billed lines', function () {
    $seed = function () {
        $s = app(ProductionSeeder::class);
        $s->historyPath = 'missing.json'; // clients, projects and hard-coded subscriptions only
        $s->run();
    };
    $seed();
    $seed(); // idempotent

    expect(Client::count())->toBe(3)->and(Project::count())->toBe(4)->and(RecurringCharge::count())->toBe(2)
        ->and(BillableLine::count())->toBe(0);

    $david = Client::where('name', 'David Zouein')->first();
    expect($david->currency)->toBe('USD')->and($david->country)->toBe('Morocco');
    $app = Project::where('name', 'Luigi Darbur App')->first();
    expect((float) $app->final_budget)->toBe(2300.0)->and($app->status)->toBe('completed')->and($app->completed_at->toDateString())->toBe('2025-11-30');

    $mark = RecurringCharge::where('description', 'Maintaining currency trading system')->first();
    expect($mark->active)->toBeTrue()->and($mark->amount_cents)->toBe(8000)
        ->and($mark->next_due_on->gte(today()))->toBeTrue()->and($mark->project->name)->toBe('Currency Trading System');

    expect(RecurringCharge::where('description', 'Wholesale trading system maintenance')->first()->statusBadge()[0])->toBe('Ended');
});

<?php

use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RecurringCharge;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds subscriptions, sent invoices and payments from the history file, once', function () {
    $path = tempnam(sys_get_temp_dir(), 'hist');
    file_put_contents($path, json_encode([
        'subscriptions' => [
            ['client' => 'David Zouein', 'description' => 'Casajose', 'amount' => 120, 'first_period' => '2021-09', 'last_period' => '2026-09', 'active' => false, 'project' => null],
            ['client' => 'David Zouein', 'description' => 'Luigi Darbouazza Mobile App', 'amount' => 120, 'first_period' => '2025-12', 'last_period' => '2026-09', 'active' => true, 'project' => 'Luigi Darbur App'],
        ],
        'invoices' => [
            ['number' => 'INV-2022-09-001', 'client' => 'David Zouein', 'issued_on' => '2022-09-30', 'period' => '2022-09', 'terms' => 'PayPal: 6% fee, optional', 'lines' => [
                ['description' => 'Casajose — Maintenance Fee', 'qty' => 1, 'unit_price' => 80, 'amount' => 80, 'subscription' => 'Casajose', 'period' => '2022-09', 'project' => null],
                ['description' => 'Casajose — Server Charge', 'qty' => 1, 'unit_price' => 20, 'amount' => 20, 'subscription' => 'Casajose', 'period' => '2022-09', 'project' => null],
            ]],
            ['number' => 'INV-2025-10-001', 'client' => 'David Zouein', 'issued_on' => '2025-10-01', 'period' => '2025-10', 'terms' => null, 'lines' => [
                ['description' => 'Customer app (iOS and Android)', 'qty' => 1, 'unit_price' => 900, 'amount' => 900, 'subscription' => null, 'period' => null, 'project' => 'Luigi Darbur App'],
                ['description' => 'Server', 'qty' => 13, 'unit_price' => 20, 'amount' => 260, 'subscription' => null, 'period' => null, 'project' => null],
            ]],
        ],
        'payments' => [
            ['client' => 'David Zouein', 'paid_on' => '2023-11-08', 'amount' => 408, 'method' => 'western_union', 'notes' => 'Sent by Mark Zouein'],
            ['client' => 'David Zouein', 'paid_on' => '2023-11-08', 'amount' => 408, 'method' => 'western_union', 'notes' => 'Sent by Mark Zouein'],
            ['client' => 'Nobody', 'paid_on' => '2023-11-08', 'amount' => 1, 'method' => 'cash', 'notes' => null],
        ],
    ]));
    $seed = function () use ($path) {
        $s = app(ProductionSeeder::class);
        $s->historyPath = $path;
        $s->run();
    };
    $seed();
    $seed(); // idempotent

    $david = Client::where('name', 'David Zouein')->first();
    expect(RecurringCharge::where('client_id', $david->id)->count())->toBe(2)
        ->and(Invoice::count())->toBe(2)->and(BillableLine::count())->toBe(4)
        ->and(Payment::where('client_id', $david->id)->count())->toBe(2) // two real same-day transfers, nothing duplicated
        ->and(BillableLine::whereNull('invoice_id')->count())->toBe(0);

    $casa = RecurringCharge::where('description', 'Casajose')->first();
    expect($casa->active)->toBeFalse()->and($casa->statusBadge()[0])->toBe('Ended')->and($casa->ended_at->toDateString())->toBe('2026-09-30');
    // one linked line per subscription and month; the server line of the same month stays unlinked
    expect($casa->lines()->pluck('period')->all())->toBe(['2022-09']);

    $app = RecurringCharge::where('description', 'Luigi Darbouazza Mobile App')->first();
    expect($app->active)->toBeTrue()->and($app->next_due_on->gte(today()))->toBeTrue()->and($app->project->name)->toBe('Luigi Darbur App')
        ->and($app->lines()->count())->toBe(0);

    $inv = Invoice::where('number', 'INV-2025-10-001')->first();
    expect($inv->status)->toBe('sent')->and($inv->total_cents)->toBe(116000)->and($inv->show_quantity)->toBeTrue()
        ->and(Invoice::where('number', 'INV-2022-09-001')->value('terms'))->toBe('PayPal: 6% fee, optional')
        ->and(BillableLine::where('description', 'Customer app (iOS and Android)')->first()->project->name)->toBe('Luigi Darbur App');
    unlink($path);
});

it('rates each invoice at the client payment rate from its month', function () {
    $path = tempnam(sys_get_temp_dir(), 'hist');
    $line = fn ($n) => ['number' => $n[0], 'client' => 'David Zouein', 'issued_on' => $n[1], 'period' => substr($n[1], 0, 7), 'lines' => [
        ['description' => 'Fee', 'qty' => 1, 'unit_price' => 80, 'amount' => 80, 'subscription' => null, 'period' => null, 'project' => null],
    ]];
    file_put_contents($path, json_encode([
        'invoices' => [$line(['INV-A', '2022-03-01']), $line(['INV-B', '2022-05-01'])],
        'payments' => [
            ['client' => 'David Zouein', 'paid_on' => '2022-03-28', 'amount' => 80, 'exchange_rate' => 70],
            ['client' => 'David Zouein', 'paid_on' => '2022-03-05', 'amount' => 80, 'exchange_rate' => 75.5],
            ['client' => 'David Zouein', 'paid_on' => '2022-04-01', 'amount' => 80, 'exchange_rate' => 90],
        ],
    ]));
    $s = app(ProductionSeeder::class);
    $s->historyPath = $path;
    $s->run();

    $a = Invoice::where('number', 'INV-A')->first();
    expect($a->local_total_cents)->toBe(604000)->and($a->lines()->first()->local_amount_cents)->toBe(604000)
        ->and(Invoice::where('number', 'INV-B')->value('exchange_rate'))->toBeNull();
    unlink($path);
});

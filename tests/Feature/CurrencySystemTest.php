<?php

use App\Livewire\Invoices\Create;
use App\Livewire\Projects\Form;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Module;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\Task;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    (new ExchangeRates)->setRate('USD', '80', $this->owner);
    (new ExchangeRates)->setRate('EUR', '100', $this->owner);
});

function csClient(string $cur = 'USD'): Client
{
    return Client::create(['name' => 'Acme '.$cur, 'currency' => $cur, 'billing_mode' => 'fixed', 'contact_email' => 'a@acme.test']);
}

it('stores currency_id and still reads the code', function () {
    $c = Client::create(['name' => 'X', 'currency' => 'usd', 'billing_mode' => 'fixed']);
    expect($c->currency)->toBe('USD')->and($c->currency_id)->toBe(Currency::where('code', 'USD')->value('id'))
        ->and(Currency::where('code', 'USD')->count())->toBe(1);
    expect(Currency::where('is_active', true)->pluck('code')->all())->toContain('USD', 'EUR');
});

it('starts a project in the client currency and lets it be changed', function () {
    $c = csClient('USD');
    $p = Project::create(['client_id' => $c->id, 'name' => 'P', 'status' => 'active', 'buffer_percent' => 0]);
    expect($p->currency)->toBe('USD');

    Livewire::actingAs($this->owner)->test(Form::class)->set('client_id', $c->id)->assertSet('currency', 'USD')
        ->set('name', 'Euro job')->set('currency', 'EUR')->call('save')->assertHasNoErrors();
    expect(Project::where('name', 'Euro job')->first()->currency)->toBe('EUR');
});

it('rejects a project currency that is not in the system', function () {
    $c = csClient('USD');
    Livewire::actingAs($this->owner)->test(Form::class)->set('client_id', $c->id)->set('name', 'X')->set('currency', 'JPY')->call('save')->assertHasErrors('currency');
});

it('keeps rate and local amount on a module budget, a task budget amount and a billable line, from the project currency', function () {
    $c = csClient('USD');
    $p = Project::create(['client_id' => $c->id, 'currency' => 'EUR', 'name' => 'P', 'status' => 'active', 'buffer_percent' => 0]);
    $m = Module::create(['project_id' => $p->id, 'name' => 'M', 'position' => 1, 'budget_amount' => '100.00', 'hourly_rate' => '25.00']);
    expect((string) $m->exchange_rate)->toBe('100.000000')->and($m->budget_amount_local)->toBe('10000.00')->and($m->hourly_rate_local)->toBe('2500.00');

    $t = Task::create(['module_id' => $m->id, 'creator_id' => $this->owner->id, 'title' => 'T', 'budget_amount' => '10.00']);
    expect((string) $t->exchange_rate)->toBe('100.000000')->and((string) $t->budget_amount_local)->toBe('1000.00');

    $l = BillableLine::create(['client_id' => $c->id, 'project_id' => $p->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 500, 'amount_cents' => 500]);
    expect($l->lineCurrency())->toBe('EUR')->and($l->local_amount_cents)->toBe(50000);

    $r = RecurringCharge::create(['client_id' => $c->id, 'description' => 'Hosting', 'amount_cents' => 300, 'frequency' => 'monthly', 'next_due_on' => today(), 'due_day' => 1]);
    expect($r->local_amount_cents)->toBe(24000); // no project: client's USD @ 80
});

it('uses a rate of 1 when the currency has no rate in the table', function () {
    $c = csClient('AED');
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 700, 'amount_cents' => 700]);
    expect((string) $l->exchange_rate)->toBe('1.000000')->and($l->local_amount_cents)->toBe(700);
});

it('recomputes the local amount when the amount changes', function () {
    $c = csClient('USD');
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 100, 'amount_cents' => 100]);
    expect($l->local_amount_cents)->toBe(8000);
    $l->update(['amount_cents' => 200]);
    expect($l->fresh()->local_amount_cents)->toBe(16000);
});

it('creates an invoice in another currency, converting lines through local currency', function () {
    $c = csClient('USD');
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 10000, 'amount_cents' => 10000]); // 100.00 USD = 8,000 INR
    $inv = app(InvoiceService::class)->create($c, [$l->id], [], 0.0, null, '100', 'EUR');                                                           // = 80.00 EUR @ 100
    expect($inv->currency)->toBe('EUR')->and($inv->total_cents)->toBe(8000)->and((string) $inv->exchange_rate)->toBe('100.000000')
        ->and($inv->local_total_cents)->toBe(800000)->and($inv->lines_snapshot[0]['amount_cents'])->toBe(8000);
});

it('always stores rate 1 and the local total on a local-currency invoice', function () {
    $c = csClient('INR');
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 5000, 'amount_cents' => 5000]);
    $inv = app(InvoiceService::class)->create($c, [$l->id]);
    expect((string) $inv->exchange_rate)->toBe('1.000000')->and($inv->local_total_cents)->toBe(5000);
});

it('invoice create page defaults to the client currency and follows a currency change', function () {
    $c = csClient('USD');
    Livewire::withQueryParams(['client' => $c->id])->actingAs($this->owner)->test(Create::class)
        ->assertSet('currency', 'USD')->assertSet('exchangeRate', '80')
        ->set('currency', 'EUR')->assertSet('exchangeRate', '100');
});

it('keeps the three money columns consistent when an invoice rate or total changes', function () {
    $c = csClient('USD');
    $l = BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 1000, 'amount_cents' => 1000]);
    $inv = app(InvoiceService::class)->create($c, [$l->id], [], 0.0, null, '80');
    expect($inv->local_total_cents)->toBe(80000);
    $inv->update(['exchange_rate' => '90']);
    expect($inv->fresh()->local_total_cents)->toBe(90000);
});

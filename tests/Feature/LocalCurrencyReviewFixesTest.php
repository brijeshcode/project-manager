<?php

use App\Livewire\Clients\Form;
use App\Livewire\Invoices\Create;
use App\Livewire\Payments\Index as Payments;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\ExchangeRate;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\InvoiceService;
use App\Services\LocalCurrency;
use App\Services\ProjectEconomics;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
});

function rfLine(Client $c, int $cents = 5000): BillableLine
{
    return BillableLine::create(['client_id' => $c->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

it('deactivates old rates when the local currency changes, keeping history', function () {
    (new ExchangeRates)->setRate('USD', '83.5', $this->owner);
    LocalCurrency::set('EUR', $this->owner);
    expect(LocalCurrency::rateFor('USD'))->toBeNull()
        ->and(ExchangeRate::count())->toBe(1)->and(ExchangeRate::where('is_active', true)->count())->toBe(0);
});

it('never 500s for a client currency that is not 3 letters', function () {
    $c = Client::create(['name' => 'Odd', 'currency' => 'US$', 'billing_mode' => 'fixed']);
    expect(LocalCurrency::rateFor('US$'))->toBeNull();
    app(InvoiceService::class)->create($c, [rfLine($c)->id])->update(['status' => 'sent']);

    $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee('1 client without a rate');
    Livewire::withQueryParams(['client' => $c->id])->actingAs($this->owner)->test(Create::class)->assertOk();
    Livewire::actingAs($this->owner)->test(Payments::class)->set('client_id', $c->id)->assertOk();
});

it('rejects a non-letter currency on the client form', function () {
    Livewire::actingAs($this->owner)->test(Form::class)->set('name', 'X')->set('currency', 'US$')->call('save')->assertHasErrors('currency');
});

it('renders economics for a tiny frozen rate', function () {
    $c = Client::create(['name' => 'Dong', 'currency' => 'VND', 'billing_mode' => 'fixed']);
    $p = Project::create(['client_id' => $c->id, 'name' => 'P', 'status' => 'active', 'buffer_percent' => 0]);
    Module::create(['project_id' => $p->id, 'name' => 'M', 'position' => 1]);
    $l = BillableLine::create(['client_id' => $c->id, 'project_id' => $p->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 100000000, 'amount_cents' => 100000000]);
    app(InvoiceService::class)->create($c, [$l->id])->forceFill(['status' => 'sent', 'exchange_rate' => '0.000038'])->save();

    expect(ProjectEconomics::for($p)['invoiced_local'])->toBe(3800);
    $this->actingAs($this->owner)->get(route('projects.economics', $p))->assertOk();
});

it('rejects an invalid exchange rate on invoice creation', function () {
    $c = Client::create(['name' => 'A', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $l = rfLine($c);
    foreach (['0', '-1', 'abc', '1.1234567'] as $bad) {
        expect(fn () => app(InvoiceService::class)->create($c, [$l->id], [], 0.0, null, $bad))->toThrow(ValidationException::class);
    }
    expect(\App\Models\Invoice::count())->toBe(0);
});

it('backfills a legacy payment when its client is switched to the local currency', function () {
    $c = Client::create(['name' => 'Sw', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $p = Payment::create(['client_id' => $c->id, 'amount_cents' => 5000, 'paid_on' => now(), 'type' => 'project']);
    Livewire::actingAs($this->owner)->test(Form::class, ['client' => $c])->set('currency', 'inr')->call('save')->assertHasNoErrors();
    $p->refresh();
    expect($p->local_amount_cents)->toBe(5000)->and((string) $p->exchange_rate)->toBe('1.000000');
});

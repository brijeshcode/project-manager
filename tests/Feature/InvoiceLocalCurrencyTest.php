<?php

use App\Livewire\Invoices\Create;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Mail\InvoiceMail;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
});

function ilClient(string $cur = 'USD'): Client
{
    return Client::create(['name' => 'Acme '.$cur, 'currency' => $cur, 'billing_mode' => 'fixed', 'contact_email' => 'a@acme.test']);
}

function ilLine(Client $c, int $cents = 100000): BillableLine
{
    return BillableLine::create(['client_id' => $c->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => $cents, 'amount_cents' => $cents]);
}

function ilCreate(Client $c)
{
    return Livewire::withQueryParams(['client' => $c->id])->actingAs(test()->owner)->test(Create::class)->assertOk();
}

it('stores the frozen rate and shows the local total on the list', function () {
    $c = ilClient();
    ilLine($c);
    ilCreate($c)->set('exchangeRate', '84')->call('save')->assertHasNoErrors();
    $inv = Invoice::firstOrFail();
    expect($inv->exchange_rate)->toBe('84.000000')->and($inv->localTotalCents())->toBe(8_400_000);
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()->assertSee('$1,000.00')->assertSee('₹84,000.00');
    Livewire::actingAs($this->owner)->test(Show::class, ['invoice' => $inv])->assertOk()->assertSee('84.000000')->assertSee('₹84,000.00');
});

it('prefills the active rate, labelled with the local currency, and a typed rate never changes it', function () {
    (new ExchangeRates)->setRate('USD', '83.5', $this->owner);
    $c = ilClient();
    ilLine($c);
    ilCreate($c)->assertSet('exchangeRate', '83.5')->assertSee('Exchange rate to INR')->set('exchangeRate', '90')->call('save')->assertHasNoErrors();
    expect(Invoice::firstOrFail()->exchange_rate)->toBe('90.000000')->and(app(ExchangeRates::class)->active('USD'))->toBe('83.500000');
});

it('needs no rate for a client already in the local currency', function () {
    $c = ilClient('INR');
    ilLine($c);
    ilCreate($c)->assertDontSee('Exchange rate to')->call('save')->assertHasNoErrors();
    $inv = Invoice::firstOrFail();
    expect($inv->exchange_rate)->toBe('1.000000')->and($inv->localTotalCents())->toBe($inv->total_cents);
    Livewire::actingAs($this->owner)->test(Index::class)->assertOk()->assertSee('₹1,000.00');
});

it('requires a rate when the currency differs and creates nothing without it', function () {
    $c = ilClient();
    ilLine($c);
    ilCreate($c)->assertSet('exchangeRate', '')->call('save')->assertHasErrors('exchangeRate');
    expect(Invoice::count())->toBe(0);
});

it('rejects invalid rates', function () {
    $c = ilClient();
    ilLine($c);
    foreach (['0', '-1', '1e5', '.5', '1.1234567', "83\n", '', 'abc', '1000000'] as $bad) {
        ilCreate($c)->set('exchangeRate', $bad)->call('save')->assertHasErrors('exchangeRate');
    }
    expect(Invoice::count())->toBe(0);
});

it('never prints the rate, local amount or local currency in the partial, email or PDF html', function () {
    $c = ilClient();
    ilLine($c);
    ilCreate($c)->set('exchangeRate', '87.654321')->call('save')->assertHasNoErrors();
    $inv = Invoice::firstOrFail()->load('client', 'lines');
    expect($inv->exchange_rate)->toBe('87.654321');

    $renders = [
        view('invoices._body', ['invoice' => $inv])->render(),
        view('invoices.pdf', ['invoice' => $inv])->render(),
        (new InvoiceMail($inv))->render(),
    ];
    foreach ($renders as $html) {
        expect($html)->toContain('$1,000.00')
            ->not->toContain('87.654321')->not->toContain('87,654,321')->not->toContain('₹')->not->toContain('INR')->not->toContain('Approx');
    }
    $this->actingAs($this->owner)->get(route('invoices.pdf', $inv))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('keeps the client portal in the client currency only', function () {
    $c = ilClient();
    $client = User::factory()->create(['client_id' => $c->id])->assignRole('client');
    ilLine($c);
    ilCreate($c)->set('exchangeRate', '87.654321')->call('save')->assertHasNoErrors();
    $this->actingAs($client)->get('/portal/invoices')->assertOk()->assertSee('$1,000.00')
        ->assertDontSee('₹')->assertDontSee('INR')->assertDontSee('87.654321');
});

it('keeps old inr_rate values when renaming to exchange_rate and reverses', function () {
    $migration = require database_path('migrations/2026_11_03_000003_rename_inr_rate_to_exchange_rate.php');
    $migration->down();
    expect(Schema::hasColumn('invoices', 'inr_rate'))->toBeTrue()->and(Schema::hasColumn('invoices', 'exchange_rate'))->toBeFalse();

    $c = ilClient();
    $id = DB::table('invoices')->insertGetId([
        'number' => 'INV-OLD-01', 'client_id' => $c->id, 'status' => 'sent', 'issued_on' => '2026-11-01', 'currency_id' => \App\Models\Currency::forCode('USD')->id,
        'subtotal_cents' => 100, 'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => 100, 'inr_rate' => 83.5, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $migration->up();

    expect(Schema::hasColumn('invoices', 'inr_rate'))->toBeFalse();
    expect(Invoice::findOrFail($id)->exchange_rate)->toBe('83.500000');
    DB::table('invoices')->where('id', $id)->update(['exchange_rate' => '87.654321']);
    expect(Invoice::findOrFail($id)->exchange_rate)->toBe('87.654321');
});

it('lets the service store the frozen rate string', function () {
    $c = ilClient();
    $inv = app(InvoiceService::class)->create($c, [ilLine($c)->id], [], 0.0, null, '84.5');
    expect($inv->fresh()->exchange_rate)->toBe('84.500000');
});

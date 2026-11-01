<?php

use App\Enums\PaymentType;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Services\ExchangeRates;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    app(ExchangeRates::class)->setRate('USD', '84.123456', $this->owner);

    $this->k = Client::create(['name' => 'Kay', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $this->ku = User::factory()->create(['client_id' => $this->k->id])->assignRole('client');
    $this->project = Project::create(['client_id' => $this->k->id, 'name' => 'Portal Project', 'status' => 'active']);

    $line = BillableLine::create(['client_id' => $this->k->id, 'description' => 'Work', 'quantity' => 1, 'unit_price_cents' => 100000, 'amount_cents' => 100000]);
    $this->invoice = app(InvoiceService::class)->create($this->k, [$line->id]);
    $this->invoice->update(['exchange_rate' => '83.5']); // $1,000.00 -> 83,500.00 local

    $this->payment = Payment::create([
        'client_id' => $this->k->id, 'project_id' => $this->project->id, 'amount_cents' => 25000, 'paid_on' => '2026-11-01',
        'type' => PaymentType::Project, 'exchange_rate' => '83.5', 'local_amount_cents' => 2087500, 'local_currency' => 'INR',
    ]);
    Storage::disk('local')->put('payment-documents/zzz.pdf', 'X');
    $this->doc = $this->payment->documents()->create(['path' => 'payment-documents/zzz.pdf', 'original_name' => 'secret-receipt-7731.pdf', 'uploaded_by' => $this->owner->id]);
});

function leaks(): array
{
    return ['₹', 'INR', '83,500', '20,875', '2087500', '83.500000', '83.5 ', '₹83', '84.123456', '84.1235', 'secret-receipt-7731', 'payment-documents', 'Local currency', 'exchange', 'Exchange'];
}

it('never shows local currency on any portal page', function (string $path) {
    $path = str_replace(['{project}'], [$this->project->id], $path);
    $r = $this->actingAs($this->ku)->get($path)->assertOk();
    foreach (leaks() as $s) {
        $r->assertDontSee($s, false);
    }
})->with(['/portal', '/portal/projects/{project}', '/portal/invoices', '/portal/payments']);

it('still shows the client their own USD amounts', function () {
    $this->actingAs($this->ku)->get('/portal/invoices')->assertOk()->assertSee('$1,000.00');
    $this->actingAs($this->ku)->get('/portal/payments')->assertOk()->assertSee('$250.00');
});

it('blocks the client from settings and document downloads', function () {
    $this->actingAs($this->ku)->get(route('settings.index'))->assertForbidden();
    $this->actingAs($this->ku)->get(route('payment-documents.download', $this->doc->id))->assertForbidden();
});

it('renders the own invoice print source without local figures, and hides others', function () {
    $html = view('invoices.pdf', ['invoice' => $this->invoice->load(['client', 'lines'])])->render();
    expect($html)->toContain('1,000.00');
    foreach (['₹', 'INR', '83,500', '83.500000', '83.5 ', '₹83', '84.123456', 'Exchange', 'exchange'] as $s) {
        expect($html)->not->toContain($s);
    }
    $this->actingAs($this->ku)->get(route('invoices.pdf', $this->invoice))->assertOk();

    $other = Client::create(['name' => 'Other', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $l = BillableLine::create(['client_id' => $other->id, 'description' => 'W', 'quantity' => 1, 'unit_price_cents' => 500, 'amount_cents' => 500]);
    $oi = app(InvoiceService::class)->create($other, [$l->id]);
    $this->actingAs($this->ku)->get(route('invoices.pdf', $oi))->assertNotFound();
});

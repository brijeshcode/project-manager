<?php

use App\Livewire\Clients\Form;
use App\Livewire\Clients\Index;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super_admin');
});

function ccInvoiceHtml(Client $c): string
{
    $invoice = Invoice::create(['number' => 'INV-CC-'.$c->id, 'client_id' => $c->id, 'status' => 'sent', 'issued_on' => today(), 'currency' => 'USD',
        'subtotal_cents' => 100, 'tax_cents' => 0, 'total_cents' => 100, 'lines_snapshot' => []]);

    return view('invoices._body', ['invoice' => $invoice->fresh()])->render();
}

it('saves the company name from the client form', function () {
    $client = Client::create(['name' => 'Nick Zouein', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    Livewire::actingAs($this->admin)->test(Form::class, ['client' => $client->fresh()])
        ->set('company_name', 'Global-Z Est.')->call('save')->assertHasNoErrors();
    expect($client->fresh()->company_name)->toBe('Global-Z Est.');
});

it('bills invoices to the company with an Attn line, or to the client name when none is set', function () {
    $withCo = Client::create(['name' => 'Nick Zouein', 'company_name' => 'Global-Z Est.', 'currency' => 'USD']);
    expect(ccInvoiceHtml($withCo))->toContain('Global-Z Est.')->toContain('Attn: Nick Zouein');

    $plain = Client::create(['name' => 'Plain Client', 'currency' => 'USD']);
    expect(ccInvoiceHtml($plain))->toContain('Plain Client')->not->toContain('Attn:');
});

it('finds a client by company name in the list', function () {
    Client::create(['name' => 'Nick Zouein', 'company_name' => 'Global-Z Est.', 'currency' => 'USD']);
    Client::create(['name' => 'Someone Else', 'currency' => 'USD']);
    Livewire::actingAs($this->admin)->test(Index::class)->set('search', 'Global-Z')
        ->assertSee('Nick Zouein')->assertSee('Global-Z Est.')->assertDontSee('Someone Else');
});

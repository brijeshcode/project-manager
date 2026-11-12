<?php

use App\Livewire\Clients\Show;
use App\Livewire\Invoices\Index;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'Toggle Co', 'currency' => 'USD']);
    Invoice::create(['number' => 'INV-T1', 'client_id' => $this->client->id, 'status' => 'sent', 'issued_on' => today(), 'currency' => 'USD', 'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000]);
});

it('invoices page switches between list and cards via the view param', function () {
    Livewire::actingAs($this->owner)->test(Index::class)
        ->assertSeeHtml('data-test="view-toggle"')->assertSeeHtml('<table')->assertDontSeeHtml('data-test="invoice-cards"')
        ->set('view', 'cards')->assertSeeHtml('data-test="invoice-cards"')->assertSee('INV-T1')->assertSee('Toggle Co')->assertDontSeeHtml('<table');

    Livewire::withQueryParams(['view' => 'bogus'])->actingAs($this->owner)->test(Index::class)
        ->assertSet('view', 'list')->assertSeeHtml('<table');
});

it('client invoices tab renders both list and cards with the switch', function () {
    Livewire::actingAs($this->owner)->test(Show::class, ['client' => $this->client])
        ->assertSeeHtml('data-test="client-invoices-list"')->assertSeeHtml('data-test="client-invoices-cards"')
        ->assertSeeHtml('clientInvoicesView')->assertSee('INV-T1');
});

it('client invoices tab changes status per invoice, scoped to this client', function () {
    $draft = Invoice::create(['number' => 'INV-D1', 'client_id' => $this->client->id, 'status' => 'draft', 'issued_on' => today(), 'currency' => 'USD', 'subtotal_cents' => 500, 'tax_cents' => 0, 'total_cents' => 500]);
    $other = Client::create(['name' => 'Other Co', 'currency' => 'USD']);
    $foreign = Invoice::create(['number' => 'INV-X1', 'client_id' => $other->id, 'status' => 'draft', 'issued_on' => today(), 'currency' => 'USD', 'subtotal_cents' => 500, 'tax_cents' => 0, 'total_cents' => 500]);

    Livewire::actingAs($this->owner)->test(Show::class, ['client' => $this->client])
        ->assertSeeHtml('setInvoiceStatus('.$draft->id.', $event.target.value)')
        ->call('setInvoiceStatus', $draft->id, 'sent');
    expect($draft->fresh()->status)->toBe('sent');

    Livewire::actingAs($this->owner)->test(Show::class, ['client' => $this->client])
        ->call('setInvoiceStatus', $foreign->id, 'sent')->assertNotFound();
    expect($foreign->fresh()->status)->toBe('draft');
});

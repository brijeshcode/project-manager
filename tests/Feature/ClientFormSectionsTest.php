<?php

use App\Livewire\Clients\Form;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'tax_number' => 'TAX-1', 'notes' => 'Keep me', 'contact_email' => 'old@acme.test', 'billing_mode' => 'fixed'])->fresh();
});

it('opens only the requested section and saves the whole client', function () {
    $c = Livewire::withQueryParams(['section' => 'contact'])->actingAs($this->admin)->test(Form::class, ['client' => $this->client])
        ->assertSet('section', 'contact')->assertSee('Edit contact details')
        ->assertSeeHtml('id="contact_email"')->assertDontSeeHtml('id="tax_number"')->assertDontSeeHtml('id="notes"');

    $c->set('contact_email', 'new@acme.test')->call('save')->assertHasNoErrors()->assertRedirect(route('clients.show', $this->client));
    $fresh = $this->client->fresh();
    expect($fresh->contact_email)->toBe('new@acme.test')->and($fresh->tax_number)->toBe('TAX-1')->and($fresh->notes)->toBe('Keep me');
});

it('falls back to every field for an unknown section or a new client', function () {
    Livewire::withQueryParams(['section' => 'nope'])->actingAs($this->admin)->test(Form::class, ['client' => $this->client])
        ->assertSet('section', '')->assertSeeHtml('id="tax_number"')->assertSeeHtml('id="contact_email"');
    Livewire::withQueryParams(['section' => 'contact'])->actingAs($this->admin)->test(Form::class)
        ->assertSet('section', '')->assertSeeHtml('id="tax_number"');
});

it('details card Edit links open their own section', function () {
    $this->actingAs($this->admin)->get(route('clients.show', $this->client))
        ->assertSee(route('clients.edit', [$this->client, 'section' => 'contact']), false)
        ->assertSee(route('clients.edit', [$this->client, 'section' => 'notes']), false);
});

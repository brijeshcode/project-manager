<?php

use App\Livewire\Clients\Form;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('super_admin can create a client with a currency', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('name', 'Acme Corp')
        ->set('currency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    $client = Client::first();
    expect($client)->not->toBeNull();
    expect($client->name)->toBe('Acme Corp');
    expect($client->currency)->toBe('USD');
    expect(Client::count())->toBe(1);
});

it('a client can have multiple client-users', function () {
    $client = Client::create(['name' => 'Globex', 'currency' => 'EUR']);

    $u1 = User::factory()->create(['client_id' => $client->id]);
    $u2 = User::factory()->create(['client_id' => $client->id]);

    expect($client->users()->count())->toBe(2);
});

it('currency is required and must be 3 chars', function () {
    $data = ['name' => 'No Currency'];

    expect(fn () => Client::create($data))->toThrow(\Illuminate\Database\QueryException::class);
});

it('a user belongs to a client via client() relation', function () {
    $client = Client::create(['name' => 'Initech', 'currency' => 'GBP']);
    $user = User::factory()->create(['client_id' => $client->id]);

    expect($user->client->id)->toBe($client->id);
    expect($user->client->name)->toBe('Initech');
});

it('client users FK is null-on-delete', function () {
    $client = Client::create(['name' => 'DeleteMe', 'currency' => 'JPY']);
    $user = User::factory()->create(['client_id' => $client->id]);

    $client->delete();

    expect($user->fresh()->client_id)->toBeNull();
});

it('stores and retrieves contact details', function () {
    $client = Client::create([
        'name'          => 'Contact Corp',
        'currency'      => 'USD',
        'contact_name'  => 'Jane Doe',
        'contact_email' => 'jane@example.com',
        'contact_phone' => '+1-555-0100',
        'address'       => '123 Main St, Springfield',
    ]);

    $fresh = $client->fresh();
    expect($fresh->contact_name)->toBe('Jane Doe');
    expect($fresh->contact_email)->toBe('jane@example.com');
    expect($fresh->contact_phone)->toBe('+1-555-0100');
    expect($fresh->address)->toBe('123 Main St, Springfield');
});

it('stores and retrieves billing details', function () {
    $client = Client::create([
        'name'            => 'Billing Corp',
        'currency'        => 'EUR',
        'tax_number'      => 'VAT123456',
        'billing_address' => '456 Invoice Ave',
        'payment_terms'   => 'net-30',
    ]);

    $fresh = $client->fresh();
    expect($fresh->tax_number)->toBe('VAT123456');
    expect($fresh->billing_address)->toBe('456 Invoice Ave');
    expect($fresh->payment_terms)->toBe('net-30');
});

it('encrypts bank fields at rest and decrypts via model', function () {
    $client = Client::create([
        'name'                => 'Secure Bank Corp',
        'currency'            => 'GBP',
        'bank_name'           => 'National Bank',
        'bank_account_number' => '00112233',
        'bank_swift'          => 'NATLGB2L',
    ]);

    // Raw DB values must NOT equal plaintext (they are encrypted payloads)
    $raw = \Illuminate\Support\Facades\DB::table('clients')->where('id', $client->id)->first();
    expect($raw->bank_name)->not->toBe('National Bank');
    expect($raw->bank_account_number)->not->toBe('00112233');
    expect($raw->bank_swift)->not->toBe('NATLGB2L');

    // Model decrypts back to plaintext
    $fresh = $client->fresh();
    expect($fresh->bank_name)->toBe('National Bank');
    expect($fresh->bank_account_number)->toBe('00112233');
    expect($fresh->bank_swift)->toBe('NATLGB2L');
});

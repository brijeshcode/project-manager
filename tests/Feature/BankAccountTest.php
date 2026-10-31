<?php

use App\Livewire\Billing\BankAccounts;
use App\Models\BankAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function baOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function baAccount(): BankAccount
{
    return BankAccount::create([
        'label' => 'Main USD', 'account_name' => 'Brijesh Co', 'bank_name' => 'HDFC',
        'account_number' => '50100012341447', 'ifsc_swift' => 'HDFC0001234',
    ]);
}

it('encrypts account number and ifsc at rest', function () {
    $a = baAccount();
    $raw = DB::table('bank_accounts')->where('id', $a->id)->first();
    expect($raw->account_number)->not->toBe('50100012341447')->and($raw->ifsc_swift)->not->toBe('HDFC0001234');
    expect($a->fresh()->account_number)->toBe('50100012341447');
});

it('hides sensitive fields from toArray and json', function () {
    $a = baAccount();
    expect($a->toArray())->not->toHaveKeys(['account_number', 'ifsc_swift']);
    expect($a->toJson())->not->toContain('50100012341447')->not->toContain('HDFC0001234');
});

it('owner adds and deletes', function () {
    Livewire::actingAs(baOwner())->test(BankAccounts::class)
        ->set('label', 'Main')->set('account_name', 'Me')->set('bank_name', 'HDFC')
        ->set('account_number', '123456781447')->set('ifsc_swift', 'HDFC0001')->set('email', 'a@b.com')
        ->call('save')->assertHasNoErrors();
    $a = BankAccount::first();
    expect($a->account_number)->toBe('123456781447');
    Livewire::actingAs(baOwner())->test(BankAccounts::class)->call('delete', $a->id);
    expect(BankAccount::count())->toBe(0);
});

it('masks the number and never renders the full one', function () {
    baAccount();
    Livewire::actingAs(baOwner())->test(BankAccounts::class)
        ->assertSee('••••1447')->assertDontSee('50100012341447')->assertDontSee('HDFC0001234');
});

it('validates input', function () {
    Livewire::actingAs(baOwner())->test(BankAccounts::class)
        ->call('save')->assertHasErrors(['label', 'account_name', 'bank_name', 'account_number', 'ifsc_swift'])
        ->set('label', str_repeat('a', 300))->set('account_number', str_repeat('1', 100))->set('email', 'nope')
        ->call('save')->assertHasErrors(['label', 'account_number', 'email']);
});

it('forbids non-owners on route and actions', function (string $role) {
    $u = User::factory()->create()->assignRole($role);
    $this->actingAs($u)->get(route('billing.bank-accounts'))->assertForbidden();

    $a = baAccount();
    $t = Livewire::actingAs(baOwner())->test(BankAccounts::class);
    $this->actingAs($u); $t->call('delete', $a->id)->assertForbidden();
    $t = Livewire::actingAs(baOwner())->test(BankAccounts::class)->set('label', 'x');
    $this->actingAs($u);
    $t->call('save')->assertForbidden();
    expect(BankAccount::count())->toBe(1);
})->with(['project_manager', 'developer', 'client']);

<?php

use App\Enums\ExpenseFrequency;
use App\Livewire\Expenses\Form;
use App\Livewire\Expenses\Index;
use App\Livewire\Expenses\Show;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    (new \App\Services\ExchangeRates)->setRate('USD', '83.5', User::factory()->create());
});

function expOwner(): User
{
    return User::factory()->create()->assignRole('super_admin');
}

function expCat(string $name = 'Server'): ExpenseCategory
{
    return ExpenseCategory::firstOrCreate(['name' => $name], ['position' => 1]);
}

function expMake(array $o = []): Expense
{
    return Expense::create(array_merge(['expense_category_id' => expCat()->id, 'description' => 'VPS', 'currency' => 'INR', 'amount_cents' => 100000, 'exchange_rate' => '1', 'incurred_on' => today()], $o));
}

it('creates a paid expense with one payment and the local amount', function () {
    $this->actingAs(expOwner());
    $domain = expCat('Domain');
    Livewire::test(Form::class)->assertSet('paid_now', true)->assertSet('currency', 'INR')
        ->set('expense_category_id', $domain->id)->set('description', 'Domain renewal')->set('payee', 'Registrar')
        ->set('currency', 'USD')->assertSet('exchange_rate', '83.500000')->set('exchange_rate', '83.5')->set('amount', '12.00')
        ->assertSee('≈ ₹1,002.00')
        ->call('save')->assertHasNoErrors()->assertRedirect();
    $e = Expense::with('payments')->first();
    expect($e->currency)->toBe('USD')->and($e->amount_cents)->toBe(1200)->and($e->status())->toBe('paid')
        ->and($e->expense_category_id)->toBe($domain->id)->and((string) $e->exchange_rate)->toBe('83.500000')->and($e->frequency)->toBeNull();
    $p = $e->payments->first();
    expect($p->amount_cents)->toBe(1200)->and($p->local_amount_cents)->toBe(100200)->and($p->local_currency)->toBe('INR')
        ->and($p->paid_on->toDateString())->toBe(today()->toDateString());
});

it('forces rate 1 for a local-currency expense', function () {
    $this->actingAs(expOwner());
    expCat();
    Livewire::test(Form::class)->set('description', 'Rent')->set('amount', '500')->set('exchange_rate', '9')->call('save')->assertHasNoErrors();
    expect((string) Expense::first()->exchange_rate)->toBe('1.000000');
});

it('creates an unpaid expense when paid now is off, and validates', function () {
    $this->actingAs(expOwner());
    expCat();
    Livewire::test(Form::class)->set('description', '')->set('amount', '0')->set('remind_days', 400)->set('expense_category_id', 999)->call('save')
        ->assertHasErrors(['description', 'amount', 'remind_days', 'expense_category_id']);
    Livewire::test(Form::class)->set('description', 'Rent')->set('amount', '500')->set('paid_now', false)->call('save')->assertHasNoErrors();
    expect(Expense::first()->status())->toBe('unpaid')->and(ExpensePayment::count())->toBe(0);
});

it('saves frequency and remind days from the form', function () {
    $this->actingAs(expOwner());
    expCat();
    Livewire::test(Form::class)->set('description', 'Domain')->set('amount', '900')->set('frequency', 'yearly')->set('remind_days', 30)->call('save')->assertHasNoErrors();
    $e = Expense::first();
    expect($e->frequency)->toBe(ExpenseFrequency::Yearly)->and($e->remind_days)->toBe(30);
    Livewire::test(Form::class, ['expense' => $e])->assertSet('frequency', 'yearly')->assertSet('remind_days', 30)
        ->set('frequency', '')->call('save')->assertHasNoErrors();
    expect($e->fresh()->frequency)->toBeNull();
});

it('adds a partial payment, then deletes it', function () {
    $this->actingAs(expOwner());
    $e = expMake();
    $t = Livewire::test(Show::class, ['expense' => $e])->assertSet('amount', '1000.00')
        ->set('amount', '400')->call('addPayment')->assertHasNoErrors()->assertSet('amount', '600.00');
    expect($e->fresh()->status())->toBe('partly');
    $p = ExpensePayment::first();
    expect($p->local_amount_cents)->toBe(40000);
    $t->call('deletePayment', $p->id);
    expect($e->fresh()->status())->toBe('unpaid');
});

it('defaults the show payment rate to the expense rate', function () {
    $this->actingAs(expOwner());
    $e = expMake(['currency' => 'USD', 'amount_cents' => 1000, 'exchange_rate' => '80']);
    Livewire::test(Show::class, ['expense' => $e])->assertSet('exchange_rate', '80.000000')->call('addPayment')->assertHasNoErrors();
    expect(ExpensePayment::first()->local_amount_cents)->toBe(80000);
});

it('edits an expense', function () {
    $this->actingAs(expOwner());
    $e = expMake();
    $this->get(route('expenses.edit', $e))->assertOk()->assertSee('Save changes')->assertDontSee('Paid now');
    Livewire::test(Form::class, ['expense' => $e])->assertSet('description', 'VPS')
        ->set('description', 'Bigger VPS')->set('amount', '1500')->set('frequency', 'monthly')->call('save')->assertHasNoErrors();
    expect($e->fresh())->description->toBe('Bigger VPS')->amount_cents->toBe(150000)->frequency->toBe(ExpenseFrequency::Monthly);
    expect(Expense::count())->toBe(1)->and(ExpensePayment::count())->toBe(0);
});

it('deletes an expense with its payments', function () {
    $this->actingAs(expOwner());
    $e = expMake();
    $e->payments()->create(['paid_on' => today(), 'amount_cents' => 100]);
    Livewire::test(Show::class, ['expense' => $e])->call('delete')->assertRedirect(route('expenses.index'));
    expect(Expense::count())->toBe(0)->and(ExpensePayment::count())->toBe(0);
});

it('lists expenses with the paid total and filters', function () {
    $this->actingAs(expOwner());
    expMake(['description' => 'Hosting box'])->payments()->create(['paid_on' => today(), 'amount_cents' => 100000, 'local_amount_cents' => 100000, 'local_currency' => 'INR']);
    $rent = expCat('Rent');
    expMake(['description' => 'Office rent', 'expense_category_id' => $rent->id]);
    $this->get(route('expenses.index'))->assertOk()->assertSee('Hosting box')->assertSee('Office rent')->assertSee('₹1,000.00');
    $this->get(route('expenses.index', ['category' => $rent->id]))->assertOk()->assertDontSee('Hosting box')->assertSee('Office rent');
    $this->get(route('expenses.index', ['search' => 'host']))->assertOk()->assertSee('Hosting box')->assertDontSee('Office rent');
});

it('filters by status due and paid', function () {
    $this->actingAs(expOwner());
    expMake(['description' => 'Paid box'])->payments()->create(['paid_on' => today(), 'amount_cents' => 100000]);
    expMake(['description' => 'Half box'])->payments()->create(['paid_on' => today(), 'amount_cents' => 100]);
    expMake(['description' => 'Open box']);
    Livewire::test(Index::class)->set('status', 'due')->assertSee('Half box')->assertSee('Open box')->assertDontSee('Paid box')
        ->set('status', 'paid')->assertSee('Paid box')->assertDontSee('Half box')->assertDontSee('Open box');
});

it('shows the due total card only when something is due, in local currency', function () {
    $this->actingAs(expOwner());
    expMake(['description' => 'Paid box'])->payments()->create(['paid_on' => today(), 'amount_cents' => 100000]);
    Livewire::test(Index::class)->assertViewHas('dueTotal', 0)->assertDontSee('Still to pay, in');
    expMake(['currency' => 'USD', 'amount_cents' => 1000, 'exchange_rate' => '80'])->payments()->create(['paid_on' => today(), 'amount_cents' => 400]);
    expMake(['amount_cents' => 5000]);
    // 600 USD cents at 80 = 48000, plus 5000 local
    Livewire::test(Index::class)->assertViewHas('dueTotal', 53000)->assertSee('Still to pay, in')->assertSee('₹530.00');
});

it('records a payment from the Pay modal at the given rate', function () {
    $this->actingAs(expOwner());
    $e = expMake(['currency' => 'USD', 'amount_cents' => 1000, 'exchange_rate' => '80']);
    Livewire::test(Index::class)->call('openPay', $e->id)->assertSet('amount', '10.00')->assertSet('exchange_rate', '80.000000')
        ->set('amount', '4')->set('exchange_rate', '82.5')->set('method', 'Card')->call('pay')->assertHasNoErrors()->assertSet('payExpenseId', null);
    $p = ExpensePayment::first();
    expect($p->amount_cents)->toBe(400)->and($p->local_amount_cents)->toBe(33000)->and($p->local_currency)->toBe('INR')->and($p->method)->toBe('Card');
    Livewire::test(Index::class)->call('openPay', $e->id)->set('paid_on', today()->addDay()->toDateString())->call('pay')->assertHasErrors('paid_on');
});

it('lists all expense payments on the Payments tab', function () {
    $this->actingAs(expOwner());
    expMake(['description' => 'Hosting box', 'payee' => 'Hetzner'])->payments()->create(['paid_on' => today(), 'amount_cents' => 2500, 'local_amount_cents' => 2500, 'local_currency' => 'INR', 'method' => 'UPI']);
    expMake(['description' => 'Unpaid thing']);
    $this->get(route('expenses.index', ['tab' => 'payments']))->assertOk()->assertSee('Hosting box')->assertSee('Hetzner')->assertSee('UPI')
        ->assertSee('₹25.00')->assertDontSee('Unpaid thing');
});

it('stores files on an expense and on a payment as attachments', function () {
    Storage::fake('local');
    $this->actingAs(expOwner());
    expCat();
    Livewire::test(Form::class)->set('description', 'Rent')->set('amount', '500')->set('paid_now', false)
        ->set('files', [UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf')])->call('save')->assertHasNoErrors();
    $e = Expense::first();
    expect($e->attachments()->pluck('name')->all())->toBe(['bill.pdf']);
    Storage::disk('local')->assertExists($e->attachments()->first()->path);

    $show = Livewire::test(Show::class, ['expense' => $e])
        ->set('expenseFiles', [UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf')])->call('uploadFiles')->assertHasNoErrors()
        ->set('files', [UploadedFile::fake()->image('receipt.png')])->call('addPayment')->assertHasNoErrors()
        ->assertSee('contract.pdf')->assertSee('receipt.png');
    expect($e->attachments()->count())->toBe(2)->and(ExpensePayment::first()->attachments()->pluck('name')->all())->toBe(['receipt.png']);

    Livewire::test(Index::class)->call('openPay', $e->id)->set('amount', '1')
        ->set('files', [UploadedFile::fake()->create('evil.php', 1)])->call('pay')->assertHasErrors('files.0');

    $show->call('deleteAttachment', $e->attachments()->first()->id);
    expect($e->attachments()->count())->toBe(1)->and(Attachment::count())->toBe(2);
});

it('is forbidden for clients and non-money users', function () {
    $e = expMake();
    foreach (['project_manager', 'developer', 'client'] as $role) {
        $this->actingAs(User::factory()->create()->assignRole($role));
        foreach ([route('expenses.index'), route('expenses.create'), route('expenses.show', $e), route('expenses.edit', $e)] as $url) {
            $this->get($url)->assertForbidden();
        }
    }
});

it('shows Expenses in the nav for the super admin only', function () {
    $this->actingAs(expOwner())->get(route('expenses.index'))->assertSee(route('expenses.index'), false);
    $this->actingAs(User::factory()->create()->assignRole('developer'))->get(route('dashboard'))->assertDontSee(route('expenses.index'), false);
});

it('never lets payments go over the due amount, on the page or from the list', function () {
    $this->actingAs(expOwner());
    $e = expMake(); // ₹1,000
    $e->payments()->create(['paid_on' => today(), 'amount_cents' => 40000, 'exchange_rate' => '1', 'local_amount_cents' => 40000, 'local_currency' => 'INR']);

    Livewire::test(Show::class, ['expense' => $e])->call('openPayment')->assertSet('amount', '600.00')
        ->set('amount', '600.01')->call('addPayment')->assertHasErrors('amount');
    Livewire::test(\App\Livewire\Expenses\Index::class)->call('openPay', $e->id)
        ->set('amount', '700')->call('pay')->assertHasErrors('amount');
    expect($e->payments()->sum('amount_cents'))->toBe(40000);

    Livewire::test(Show::class, ['expense' => $e])->set('amount', '600')->call('addPayment')->assertHasNoErrors();
    expect($e->fresh()->status())->toBe('paid');
});

it('shows the add payment button only while something is due, and no open payment form', function () {
    $this->actingAs(expOwner());
    $e = expMake();
    $this->get(route('expenses.show', $e))->assertSee('data-test="add-payment"', false);
    $e->payments()->create(['paid_on' => today(), 'amount_cents' => 100000, 'exchange_rate' => '1', 'local_amount_cents' => 100000, 'local_currency' => 'INR']);
    $this->get(route('expenses.show', $e))->assertDontSee('data-test="add-payment"', false);
});

it('hides paid and due on the details page once fully paid', function () {
    $this->actingAs(expOwner());
    $e = expMake();
    $this->get(route('expenses.show', $e))->assertSee('data-test="due"', false);
    $e->payments()->create(['paid_on' => today(), 'amount_cents' => 100000, 'exchange_rate' => '1', 'local_amount_cents' => 100000, 'local_currency' => 'INR']);
    $this->get(route('expenses.show', $e))->assertDontSee('data-test="due"', false);
});

it('edits a payment, allowing up to the due amount plus its own', function () {
    $this->actingAs(expOwner());
    $e = expMake(); // ₹1,000
    $p = $e->payments()->create(['paid_on' => today(), 'amount_cents' => 40000, 'exchange_rate' => '1', 'local_amount_cents' => 40000, 'local_currency' => 'INR', 'method' => 'cash']);

    $lw = Livewire::test(Show::class, ['expense' => $e])->call('editPayment', $p->id)
        ->assertSet('amount', '400.00')->assertSet('method', 'cash')->assertSee('Edit payment');
    $lw->set('amount', '1000.01')->call('addPayment')->assertHasErrors('amount');
    $lw->set('amount', '1000')->set('method', 'bank')->call('addPayment')->assertHasNoErrors();

    expect($e->payments()->count())->toBe(1)
        ->and($p->fresh())->amount_cents->toBe(100000)->local_amount_cents->toBe(100000)->method->toBe('bank')
        ->and($e->fresh()->status())->toBe('paid');
});

<?php

use App\Enums\PaymentType;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectEconomics;
use App\Support\DashboardMoney;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

afterEach(fn () => Carbon::setTestNow());

function reportExpense(string $cur, int $cents, string $on, array $extra = []): Expense
{
    return Expense::create($extra + ['expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'Server'])->id, 'description' => 'VPS', 'currency_id' => Currency::forCode($cur)->id,
        'amount_cents' => $cents, 'incurred_on' => $on]);
}

function expPay(Expense $e, int $cents, string $on, ?int $local): void
{
    ExpensePayment::create(['expense_id' => $e->id, 'paid_on' => $on, 'amount_cents' => $cents,
        'local_amount_cents' => $local, 'local_currency' => $local === null ? null : 'INR']);
}

it('dashboard subtracts local-currency expense payments in the range from revenue', function () {
    $c = Client::create(['name' => 'Bharat', 'currency' => 'INR', 'billing_mode' => 'fixed']);
    Payment::create(['client_id' => $c->id, 'amount_cents' => 1_000_00, 'paid_on' => '2026-11-10', 'type' => PaymentType::Project,
        'local_amount_cents' => 1_000_00, 'local_currency' => 'INR', 'exchange_rate' => '1']);
    $e = reportExpense('USD', 1000, '2026-11-01');
    expPay($e, 500, '2026-11-05', 400_00);
    expPay($e, 500, '2026-10-31', 999_00); // before the range
    expPay($e, 100, '2026-11-06', null); // no rate

    $m = DashboardMoney::forPeriodLocal(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
    expect($m['expenses'])->toBe(400_00)->and($m['net'])->toBe(600_00)->and($m['expenses_missing_rate'])->toBe(1);

    Carbon::setTestNow('2026-11-20');
    actingAs(User::factory()->create()->assignRole('super_admin'))->get('/dashboard')->assertOk()
        ->assertSee('Net Income')->assertSee('₹400.00', false)->assertSee('₹600.00', false)
        ->assertSee('1 expense payment has no exchange rate');
});

it('project profit subtracts linked expenses in the project currency only', function () {
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $p = Project::create(['client_id' => $c->id, 'name' => 'P', 'status' => 'active', 'buffer_percent' => 0]);
    expPay(reportExpense('USD', 3000, '2026-11-01', ['project_id' => $p->id]), 2500, '2026-11-02', null);
    expPay(reportExpense('EUR', 9000, '2026-11-01', ['project_id' => $p->id]), 9000, '2026-11-02', null);
    expPay(reportExpense('USD', 7000, '2026-11-01'), 7000, '2026-11-02', null); // not linked

    $e = ProjectEconomics::for($p->fresh());
    expect($e['expense_cents'])->toBe(2500)->and($e['profit_cents'])->toBe(-2500);
});

function copyDates(Expense $t): array
{
    return Expense::where('repeated_from_id', $t->id)->orderBy('incurred_on')->get()->map(fn ($c) => $c->incurred_on->toDateString())->all();
}

it('recurring command catches up missed copies within the reminder window, clamps the day and is idempotent', function () {
    Carbon::setTestNow('2026-03-15');
    $t = reportExpense('USD', 5000, '2025-12-31', ['frequency' => 'monthly', 'remind_days' => 0, 'exchange_rate' => '83.5']);
    expPay($t, 5000, '2025-12-31', 5000);
    reportExpense('INR', 100, '2026-03-02', ['frequency' => 'monthly', 'remind_days' => 7]); // next copy 2 Apr is 18 days out

    artisan('expenses:generate-recurring')->assertSuccessful();
    artisan('expenses:generate-recurring')->assertSuccessful();

    $copies = Expense::where('repeated_from_id', $t->id)->orderBy('incurred_on')->get();
    expect(copyDates($t))->toBe(['2026-01-31', '2026-02-28']) // 31 Mar is 16 days out, no lead time
        ->and($copies->every(fn ($c) => $c->frequency === null && $c->amount_cents === 5000 && $c->exchange_rate === '83.500000'
            && $c->expense_category_id === $t->expense_category_id && $c->remind_days === 0 && $c->payments()->doesntExist()))->toBeTrue()
        ->and(Expense::count())->toBe(4);

    Carbon::setTestNow('2026-03-31');
    artisan('expenses:generate-recurring')->assertSuccessful();
    expect(copyDates($t))->toBe(['2026-01-31', '2026-02-28', '2026-03-31']); // day kept after February
});

it('recurring command steps quarterly, half-yearly and yearly', function () {
    Carbon::setTestNow('2027-02-01');
    $q = reportExpense('INR', 100, '2026-01-15', ['frequency' => 'quarterly', 'remind_days' => 0]);
    $h = reportExpense('INR', 100, '2025-08-31', ['frequency' => 'half_yearly', 'remind_days' => 0]);
    $y = reportExpense('INR', 100, '2025-01-10', ['frequency' => 'yearly', 'remind_days' => 0]);

    artisan('expenses:generate-recurring')->assertSuccessful();

    expect(copyDates($q))->toBe(['2026-04-15', '2026-07-15', '2026-10-15', '2027-01-15'])
        ->and(copyDates($h))->toBe(['2026-02-28', '2026-08-31'])
        ->and(copyDates($y))->toBe(['2026-01-10', '2027-01-10']);
});

it('a yearly bill is created remind_days before its date, not earlier', function () {
    $t = reportExpense('INR', 100, '2026-01-31', ['frequency' => 'yearly', 'remind_days' => 30]);

    Carbon::setTestNow('2026-12-31'); // 31 days before 31 Jan 2027
    artisan('expenses:generate-recurring')->assertSuccessful();
    expect(copyDates($t))->toBe([]);

    Carbon::setTestNow('2027-01-01'); // 30 days before
    artisan('expenses:generate-recurring')->assertSuccessful();
    expect(copyDates($t))->toBe(['2027-01-31']);
});

it('dashboard lists unpaid bills inside their reminder window with a local total, and nothing otherwise', function () {
    Carbon::setTestNow('2026-11-20');
    $admin = User::factory()->create()->assignRole('super_admin');

    actingAs($admin)->get('/dashboard')->assertOk()->assertDontSee('Bills due');

    $late = reportExpense('USD', 10_00, '2026-11-10', ['description' => 'Old VPS', 'exchange_rate' => '80']);
    expPay($late, 4_00, '2026-11-11', 320_00); // 6.00 USD left = ₹480
    reportExpense('INR', 200_00, '2026-11-25', ['description' => 'Rent bill', 'exchange_rate' => '1', 'remind_days' => 7]);
    reportExpense('INR', 999_00, '2026-12-10', ['description' => 'Far bill', 'exchange_rate' => '1', 'remind_days' => 7]); // not yet
    $paid = reportExpense('INR', 50_00, '2026-11-01', ['description' => 'Paid bill', 'exchange_rate' => '1']);
    expPay($paid, 50_00, '2026-11-01', 50_00);

    actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Bills due')
        ->assertSee('Old VPS')->assertSee('overdue')->assertSee('Rent bill')->assertSee('due in 5 days')
        ->assertDontSee('Far bill')->assertDontSee('Paid bill')
        ->assertSeeInOrder(['data-test="bills-total"', '₹680.00'], false);
});

it('settings: expense categories can be added, renamed, reordered and deleted by money users only', function () {
    $admin = User::factory()->create()->assignRole('super_admin');
    actingAs(User::factory()->create()->assignRole('project_manager'))->get('/settings/expense-categories')->assertForbidden();
    actingAs($admin)->get('/settings/expense-categories')->assertOk()->assertSee('Expense categories');

    $c = Livewire::actingAs($admin)->test(\App\Livewire\Settings\ExpenseCategories::class)
        ->call('create')->set('name', 'Hosting')->call('save')->assertHasNoErrors()
        ->call('create')->set('name', 'Hosting')->call('save')->assertHasErrors('name');
    $hosting = ExpenseCategory::where('name', 'Hosting')->firstOrFail();
    $e = reportExpense('INR', 100, '2026-11-01', ['expense_category_id' => $hosting->id]);

    $c->call('edit', $hosting->id)->set('name', 'Cloud')->call('save')->assertHasNoErrors();
    expect($hosting->fresh()->name)->toBe('Cloud');

    $first = ExpenseCategory::orderBy('position')->first();
    $c->call('reorder', [$hosting->id, $first->id]);
    expect(ExpenseCategory::orderBy('position')->first()->id)->toBe($hosting->id);

    $c->call('delete', $hosting->id);
    expect(ExpenseCategory::find($hosting->id))->toBeNull()->and($e->fresh()->expense_category_id)->toBeNull();
});

it('expense and expense payment files download only for money users', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/x/bill.pdf', 'PDF');
    $e = reportExpense('INR', 100, '2026-11-01');
    expPay($e, 100, '2026-11-02', 100);
    $files = [
        $e->attachments()->create(['path' => 'attachments/x/bill.pdf', 'name' => 'bill.pdf', 'mime' => 'application/pdf', 'size' => 3]),
        $e->payments()->first()->attachments()->create(['path' => 'attachments/x/bill.pdf', 'name' => 'receipt.pdf', 'mime' => 'application/pdf', 'size' => 3]),
    ];

    foreach ($files as $a) {
        actingAs(User::factory()->create()->assignRole('client'))->get($a->url())->assertForbidden();
        actingAs(User::factory()->create()->assignRole('project_manager'))->get($a->url())->assertForbidden();
        actingAs(User::factory()->create()->assignRole('super_admin'))->get($a->url())->assertOk();
    }
});

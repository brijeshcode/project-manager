<?php

use App\Livewire\Clients\Form;
use App\Livewire\Clients\Index;
use App\Livewire\Clients\Show;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function cpAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('super_admin');

    return $u;
}

function cpClient(array $o = []): Client
{
    return Client::create($o + ['name' => 'Acme', 'currency' => 'USD']);
}

function cpInvoice(Client $c, int $total, string $status = 'sent', string $n = 'INV-1'): Invoice
{
    return Invoice::create(['number' => $n, 'client_id' => $c->id, 'status' => $status, 'issued_on' => today(), 'currency' => 'USD', 'subtotal_cents' => $total, 'tax_cents' => 0, 'total_cents' => $total]);
}

function cpPayment(Client $c, int $cents): Payment
{
    return Payment::create(['client_id' => $c->id, 'amount_cents' => $cents, 'paid_on' => today(), 'type' => 'project']);
}

// ---- model
it('generates sequential unique client codes', function () {
    $codes = collect(range(1, 4))->map(fn ($i) => cpClient(['name' => "C$i"])->client_code)->all();
    expect($codes)->toBe(['CLI-0001', 'CLI-0002', 'CLI-0003', 'CLI-0004']);
    expect(Client::first()->type->value)->toBe('company')->and(Client::first()->status->value)->toBe('active');
});

it('code generation continues past the highest code even after a delete', function () {
    cpClient(); $b = cpClient(); $c = cpClient();
    $c->delete();
    expect(cpClient()->client_code)->toBe('CLI-0003');
    $b->delete();
    expect(cpClient()->client_code)->toBe('CLI-0004');
});

// ---- index
it('index lists columns, billed and balance with exact amounts', function () {
    $c = cpClient(['name' => 'Globex', 'contact_name' => 'Hank', 'contact_email' => 'hank@globex.test', 'contact_phone' => '555']);
    cpInvoice($c, 150000, 'sent', 'A'); cpInvoice($c, 99900, 'void', 'B'); cpInvoice($c, 25050, 'draft', 'D');
    cpPayment($c, 40000);

    Livewire::actingAs(cpAdmin())->test(Index::class)->assertOk()
        ->assertSee(['Client', 'Contact', 'Financial Summary', 'Status', 'Actions'])
        ->assertSee('Globex')->assertSee('CLI-0001')->assertSee('hank@globex.test')
        ->assertSee('Billed: $1,500.00')->assertSee('Balance: -$1,100.00')
        ->assertDontSee('999.00')->assertSee('active');
    expect(app(\App\Services\ClientAccount::class)->balanceCents($c))->toBe(-110000);
});

it('index filters by status and searches by code, contact and email', function () {
    cpClient(['name' => 'Alpha', 'contact_name' => 'Zed Person']);
    cpClient(['name' => 'Beta', 'status' => 'archived', 'contact_email' => 'who@beta.test']);
    $t = Livewire::actingAs(cpAdmin())->test(Index::class)->assertOk();
    $t->set('status', 'archived')->assertSee('Beta')->assertDontSee('Alpha');
    $t->set('status', '')->set('search', 'CLI-0001')->assertSee('Alpha')->assertDontSee('Beta');
    $t->set('search', 'zed')->assertSee('Alpha')->assertDontSee('Beta');
    $t->set('search', 'who@beta')->assertSee('Beta')->assertDontSee('Alpha');
});

it('index per-page is validated and defaults to 15', function () {
    foreach (range(1, 20) as $i) { cpClient(['name' => sprintf('Client %02d', $i)]); }
    $t = Livewire::actingAs(cpAdmin())->test(Index::class)->assertOk()->assertSee('Client 15')->assertDontSee('Client 16');
    $t->set('perPage', 7)->assertOk()->assertSee('Client 15')->assertDontSee('Client 16');
    $t->set('perPage', 25)->assertOk()->assertSee('Client 20');
});

it('index computes totals with grouped queries, not per row', function () {
    foreach (range(1, 12) as $i) { $c = cpClient(['name' => "C$i"]); cpInvoice($c, 100, 'sent', "I$i"); }
    DB::enableQueryLog();
    Livewire::actingAs(cpAdmin())->test(Index::class)->assertOk();
    $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "invoices"') || str_contains($q['query'], 'from `invoices`'))->count();
    expect($n)->toBe(1);
});

it('index links to view, edit and add project', function () {
    $c = cpClient();
    Livewire::actingAs(cpAdmin())->test(Index::class)->assertOk()
        ->assertSee(route('clients.show', $c))->assertSee(route('clients.edit', $c))
        ->assertSee(route('projects.create', ['client' => $c->id]), false);
});

it('non-owners cannot reach client pages', function (string $role) {
    $c = cpClient();
    $u = User::factory()->create();
    $u->assignRole($role);
    $this->actingAs($u)->get(route('clients.show', $c))->assertForbidden();
    Livewire::actingAs($u)->test(Show::class, ['client' => $c])->assertForbidden();
})->with(['developer', 'client', 'project_manager']);

// ---- show
it('show renders summary, tabs, notes, and never bank details', function () {
    $c = cpClient(['name' => 'Initech', 'notes' => 'Pays late, be kind', 'bank_account_number' => '99887766', 'bank_name' => 'Zeta Bank', 'tax_number' => 'TX-1', 'website' => 'https://initech.test']);
    $p = Project::create(['client_id' => $c->id, 'name' => 'Portal Build', 'status' => 'active']);
    $inv = cpInvoice($c, 20000, 'sent', 'INV-77');
    cpPayment($c, 5000);

    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $c])->assertOk()
        ->assertSee(['Initech', 'CLI-0001', 'Total Billed', 'Total Received', 'Current Balance', '$200.00', '$50.00', '-$150.00'])
        ->assertSee(['Pays late, be kind', 'TX-1', 'https://initech.test', 'Portal Build', 'INV-77'])
        ->assertSee(route('projects.show', $p))->assertSee(route('invoices.show', $inv))
        ->assertDontSee('99887766')->assertDontSee('Zeta Bank');
});

it('show has empty states and a 404 for an unknown client', function () {
    $c = cpClient();
    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $c])->assertOk()->assertSee('No projects yet')->assertSee('No invoices yet');
    $this->actingAs(cpAdmin())->get('/clients/9999')->assertNotFound();
});

it('portal never exposes client notes', function () {
    $c = cpClient(['notes' => 'SECRET-INTERNAL']);
    $u = User::factory()->create(['client_id' => $c->id]);
    $u->assignRole('client');
    Project::create(['client_id' => $c->id, 'name' => 'P', 'status' => 'active']);
    foreach (['portal.dashboard', 'portal.invoices', 'portal.payments'] as $r) {
        $this->actingAs($u)->get(route($r))->assertOk()->assertDontSee('SECRET-INTERNAL');
    }
});

// ---- delete
it('deletes an empty client from the index with a toast', function () {
    $c = cpClient();
    Livewire::actingAs(cpAdmin())->test(Index::class)->call('delete', $c->id)
        ->assertDispatched('toast', type: 'success', message: 'Client deleted.');
    expect(Client::count())->toBe(0);
});

it('deleting from show redirects to the list with a flash toast', function () {
    $c = cpClient();
    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $c])->call('delete', $c->id)
        ->assertRedirect(route('clients.index'));
    expect(Client::count())->toBe(0)->and(session('toast'))->toBe('Client deleted.');
});

it('refuses to delete a client that has related data', function (string $what, Closure $make) {
    $c = cpClient();
    $make($c);
    Livewire::actingAs(cpAdmin())->test(Index::class)->call('delete', $c->id)
        ->assertDispatched('toast', fn ($name, $p) => $p['type'] === 'error' && str_contains($p['message'], $what) && str_contains($p['message'], 'archiv'));
    expect(Client::count())->toBe(1);
    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $c])->call('delete', $c->id)->assertNoRedirect()->assertDispatched('toast', fn ($n, $p) => $p['type'] === 'error');
    expect(Client::count())->toBe(1);
})->with([
    'projects' => ['projects', fn ($c) => Project::create(['client_id' => $c->id, 'name' => 'P', 'status' => 'active'])],
    'invoices' => ['invoices', fn ($c) => cpInvoice($c, 100)],
    'payments' => ['payments', fn ($c) => cpPayment($c, 5)],
    'users' => ['users', fn ($c) => User::factory()->create(['client_id' => $c->id])],
    'billable lines' => ['billable lines', fn ($c) => BillableLine::create(['client_id' => $c->id, 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 5, 'amount_cents' => 5])],
    'recurring' => ['recurring charges', fn ($c) => RecurringCharge::create(['client_id' => $c->id, 'description' => 'Hosting', 'amount_cents' => 100, 'frequency' => 'monthly', 'next_due_on' => today(), 'due_day' => 1, 'active' => true])],
]);

it('a non-owner cannot delete', function () {
    $c = cpClient();
    $u = User::factory()->create();
    $u->assignRole('developer');
    Livewire::actingAs($u)->test(Index::class)->assertForbidden();
    // the component can only be mounted by an owner; replay the action as a developer
    $t = Livewire::actingAs(cpAdmin())->test(Index::class);
    $this->actingAs($u);
    $t->call('delete', $c->id)->assertForbidden();
    expect(Client::count())->toBe(1);
});

it('deleting a missing client is a 404', function () {
    expect(fn () => Livewire::actingAs(cpAdmin())->test(Index::class)->call('delete', 9999))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class); // renders as 404 over HTTP
});

// ---- form
it('creates a client with the new fields', function () {
    Livewire::actingAs(cpAdmin())->test(Form::class)
        ->set('name', 'Wayne')->set('currency', 'usd')->set('type', 'individual')->set('status', 'inactive')
        ->set('contact_name', 'ignored for individuals')->set('website', 'https://wayne.test')->set('city', 'Gotham')
        ->set('state', 'NJ')->set('postal_code', '07001')->set('country', 'US')->set('notes', 'private')
        ->call('save')->assertHasNoErrors();
    $c = Client::first();
    expect($c->type->value)->toBe('individual')->and($c->status->value)->toBe('inactive')->and($c->city)->toBe('Gotham')
        ->and($c->notes)->toBe('private')->and($c->client_code)->toBe('CLI-0001')->and($c->contact_name)->toBeNull();
});

it('rejects bad type and status', function () {
    Livewire::actingAs(cpAdmin())->test(Form::class)->set('name', 'X')->set('currency', 'USD')
        ->set('type', 'robot')->set('status', 'zombie')->call('save')->assertHasErrors(['type', 'status']);
    expect(Client::count())->toBe(0);
});

it('edit saves, keeps the code, and redirects to show with a toast', function () {
    $c = cpClient();
    Livewire::actingAs(cpAdmin())->test(Form::class, ['client' => $c->fresh()])->assertOk()->assertSee('CLI-0001')
        ->set('name', 'Renamed')->set('status', 'archived')->call('save')
        ->assertRedirect(route('clients.show', $c));
    expect($c->fresh()->name)->toBe('Renamed')->and($c->fresh()->status->value)->toBe('archived')->and($c->fresh()->client_code)->toBe('CLI-0001');
    expect(session('toast'))->toBe('Client saved.');
});

it('preselects the client on a new project via query string', function () {
    $c = cpClient();
    Livewire::withQueryParams(['client' => $c->id])->actingAs(cpAdmin())->test(\App\Livewire\Projects\Form::class)->assertSet('client_id', $c->id);
});

it('rejects a tampered clientId on the form', function () {
    $a = cpClient(['name' => 'A']);
    $b = cpClient(['name' => 'B']);
    expect(fn () => Livewire::actingAs(cpAdmin())->test(Form::class, ['client' => $a->fresh()])->set('clientId', $b->id))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::actingAs(cpAdmin())->test(Form::class)->set('clientId', $b->id))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});

it('index corrects a bad perPage back to 15', function () {
    Livewire::actingAs(cpAdmin())->test(Index::class)->set('perPage', 7)->assertSet('perPage', 15)->set('perPage', 50)->assertSet('perPage', 50);
});

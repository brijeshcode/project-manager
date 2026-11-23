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
        ->assertSee(['Name', 'Contact', 'Subscriptions', 'Balance', 'Actions'])
        ->assertSee('Globex')->assertSee('hank@globex.test')
        ->assertSee('$1,100.00')->assertSee('Client owes')
        ->assertDontSee('999.00');
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
    expect($n)->toBeLessThanOrEqual(3); // constant: client totals, local totals, subscription usage
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
    $c = cpClient(['notes' => 'SECRET-INTERNAL', 'portal_features' => array_keys(Client::PORTAL_FEATURES)]);
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
    Livewire::actingAs(cpAdmin())->test(Form::class, ['client' => $c->fresh()])->assertOk()
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

it('show lists this client\'s payments with type, project, invoice and method — and no one else\'s', function () {
    $c = cpClient(['name' => 'Initech']);
    $other = cpClient(['name' => 'Other Co']);
    $p = Project::create(['client_id' => $c->id, 'name' => 'Portal Build', 'status' => 'active']);
    $inv = cpInvoice($c, 20000, 'sent', 'INV-88');
    Payment::create(['client_id' => $c->id, 'project_id' => $p->id, 'invoice_id' => $inv->id, 'amount_cents' => 123400, 'paid_on' => '2026-10-05', 'type' => 'project_update', 'method' => 'Bank transfer', 'notes' => 'PRIVATE-NOTE']);
    cpPayment($other, 987600);

    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $c])->assertOk()
        ->assertSee(['$1,234.00', 'Oct 5, 2026', 'Project update', 'Portal Build', 'INV-88', 'Bank transfer'])
        ->assertSee(route('invoices.show', $inv))
        ->assertDontSee('$9,876.00')->assertDontSee('PRIVATE-NOTE')->assertDontSee('No payments yet');
});

it('show has a payments empty state', function () {
    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => cpClient()])->assertOk()->assertSee('No payments yet');
});

it('client page manages portal logins and status', function () {
    $admin = cpAdmin();
    $client = cpClient();
    $other = cpClient(['name' => 'Other Co']);
    $c = Livewire::actingAs($admin)->test(Show::class, ['client' => $client])
        ->set('login', ['name' => 'Pat', 'email' => 'pat@example.test', 'password' => 'secret-pass-123'])
        ->call('addLogin')->assertHasNoErrors();
    $u = User::where('email', 'pat@example.test')->first();
    expect($u->client_id)->toBe($client->id)->and($u->hasRole('client'))->toBeTrue();

    $c->set('newPassword.'.$u->id, 'another-pass-456')->call('setLoginPassword', $u->id)->assertHasNoErrors();
    expect(\Illuminate\Support\Facades\Hash::check('another-pass-456', $u->fresh()->password))->toBeTrue();

    $foreign = User::factory()->create(['client_id' => $other->id]);
    $foreign->assignRole('client');
    foreach ([$foreign->id, $admin->id] as $id) {
        Livewire::actingAs($admin)->test(Show::class, ['client' => $client])->call('removeLogin', $id)->assertNotFound();
    }
    expect(User::find($foreign->id))->not->toBeNull();
    $c->call('removeLogin', $u->id);
    expect(User::find($u->id))->toBeNull();

    $c->call('setStatus', 'inactive');
    expect($client->fresh()->status->value)->toBe('inactive');
    $c->assertSee(route('invoices.create', ['client' => $client->id]), false)->assertSee(route('payments.create', ['client' => $client->id]), false);
});

it('client page tabs edit and delete projects, invoices, payments and subscriptions of this client only', function () {
    $admin = cpAdmin();
    $client = cpClient();
    $other = cpClient(['name' => 'Other Co']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Empty Proj', 'status' => 'active']);
    $paidProject = Project::create(['client_id' => $client->id, 'name' => 'Paid Proj', 'status' => 'active']);
    Payment::create(['client_id' => $client->id, 'project_id' => $paidProject->id, 'amount_cents' => 100, 'paid_on' => today(), 'type' => 'project']);
    $invoice = cpInvoice($client, 500);
    $payment = cpPayment($client, 300);
    $sub = RecurringCharge::create(['client_id' => $client->id, 'description' => 'Hosting', 'amount_cents' => 100, 'frequency' => 'monthly', 'next_due_on' => today(), 'active' => true]);
    $foreignPayment = cpPayment($other, 50);

    $page = fn () => Livewire::actingAs($admin)->test(Show::class, ['client' => $client]);
    $page()->assertSee(route('projects.edit', $project), false)->assertSee(route('invoices.edit', $invoice), false)
        ->assertSee(route('payments.edit', $payment), false)->assertSee(route('billing.recurring.edit', ['charge' => $sub->id]), false)
        ->assertSeeHtml('data-test="confirm-modal"');

    $page()->call('deleteProject', $project->id)->call('deleteProject', $paidProject->id)
        ->call('deleteInvoice', $invoice->id)->call('deletePayment', $payment->id)->call('deleteSubscription', $sub->id);
    expect(Project::find($project->id))->toBeNull()->and(Project::find($paidProject->id))->not->toBeNull()
        ->and(Invoice::find($invoice->id))->toBeNull()->and(Payment::find($payment->id))->toBeNull()->and(RecurringCharge::find($sub->id))->toBeNull();

    $page()->call('deletePayment', $foreignPayment->id)->assertNotFound();
    expect(Payment::find($foreignPayment->id))->not->toBeNull();
});

it('projects tab shows progress, counts and portal visibility per project', function () {
    $client = cpClient();
    Project::create(['client_id' => $client->id, 'name' => 'Live Proj', 'status' => 'active']);
    Project::create(['client_id' => $client->id, 'name' => 'Draft Proj', 'status' => 'draft']);
    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $client])
        ->assertSee('Live Proj')->assertSee('Draft Proj')->assertSee('1 visible in the client portal')
        ->assertSee('In client portal')->assertSee('Hidden from portal')->assertSee('0 / 0 done');
});

it('blocking portal access stops client sign-in and signs out active sessions', function () {
    $client = cpClient();
    $user = User::factory()->create(['client_id' => $client->id, 'password' => bcrypt('pass-123456')]);
    $user->assignRole('client');
    $staff = cpAdmin();

    Livewire::actingAs($staff)->test(Show::class, ['client' => $client])->assertSee('Block portal access')->call('togglePortalBlock');
    expect($client->fresh()->portalBlocked())->toBeTrue();

    $this->actingAs($user)->get(route('portal.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    Livewire::test('pages.auth.login')->set('form.email', $user->email)->set('form.password', 'pass-123456')->call('login')->assertHasErrors('form.email');
    $this->assertGuest();

    $this->actingAs($staff)->get(route('dashboard'))->assertOk(); // staff unaffected
    Livewire::actingAs($staff)->test(Show::class, ['client' => $client])->call('togglePortalBlock');
    expect($client->fresh()->portalBlocked())->toBeFalse();
});

it('hides payments, statement and billing totals from the portal until ticked per client', function () {
    $client = cpClient();
    $user = User::factory()->create(['client_id' => $client->id])->assignRole('client');
    $hidden = fn () => $this->actingAs($user);

    $hidden()->get(route('portal.payments'))->assertNotFound();
    $hidden()->get(route('portal.statement'))->assertNotFound();
    $hidden()->get(route('portal.dashboard'))->assertOk()->assertDontSee('Account balance')->assertDontSee('Recent payments')->assertDontSee(route('portal.payments'));
    $hidden()->get(route('portal.invoices'))->assertOk()->assertDontSee('Total invoiced');
    $hidden()->get(route('portal.subscriptions'))->assertOk()->assertDontSee('Invoiced to date');

    $show = Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $client]);
    foreach (array_keys(Client::PORTAL_FEATURES) as $f) {
        $show->call('togglePortalFeature', $f);
    }
    expect($client->fresh()->portal_features)->toBe(['payments', 'statement', 'billing_totals']);

    $user->refresh();
    $this->actingAs($user)->get(route('portal.payments'))->assertOk();
    $this->actingAs($user)->get(route('portal.statement'))->assertOk();
    $this->actingAs($user)->get(route('portal.dashboard'))->assertSee('Account balance')->assertSee('Recent payments');
    $this->actingAs($user)->get(route('portal.invoices'))->assertSee('Total invoiced');
    $this->actingAs($user)->get(route('portal.subscriptions'))->assertSee('Invoiced to date');

    $show = Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $client])->call('togglePortalFeature', 'payments');
    expect($client->fresh()->portal_features)->toBe(['statement', 'billing_totals']);
    $this->actingAs($user->refresh())->get(route('portal.payments'))->assertNotFound();
    $show->call('togglePortalFeature', 'bogus')->assertNotFound();
});

it('records the date a client stopped being active', function () {
    $client = cpClient();
    $client->update(['status' => 'inactive']);
    expect($client->fresh()->inactive_since->isToday())->toBeTrue();
    $client->update(['status' => 'active']);
    expect($client->fresh()->inactive_since)->toBeNull();
});

it('client page tab filters narrow the list and a chip clears its filter', function () {
    $c = cpClient();
    Project::create(['client_id' => $c->id, 'name' => 'Alpha Build', 'status' => 'active']);
    Project::create(['client_id' => $c->id, 'name' => 'Beta Site', 'status' => 'completed']);

    Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $c])
        ->assertSee(['Alpha Build', 'Beta Site'])->assertDontSee('Status: Completed')
        ->set('projectStatus', 'completed')
        ->assertSee('Beta Site')->assertDontSee('Alpha Build')
        ->assertSee('Status: Completed')->assertSeeHtml("wire:click=\"\$set('projectStatus', '')\"")
        ->set('projectSearch', 'zzz')->assertSee('No projects match your filters.')
        ->set('projectStatus', '')->set('projectSearch', '')
        ->assertSee(['Alpha Build', 'Beta Site'])->assertDontSee('Status: Completed');
});

it('client page list tabs show stats that follow the tab filters, and search is a counted chip', function () {
    $c = cpClient();
    cpInvoice($c, 20000, 'sent', 'INV-A');
    cpInvoice($c, 5000, 'sent', 'INV-B');
    cpPayment($c, 7000);

    $stats = fn ($t) => Str::before(Str::after($t->html(), 'data-test="client-invoice-stats"'), 'data-test="filter-panel"');
    $t = Livewire::actingAs(cpAdmin())->test(Show::class, ['client' => $c])
        ->assertSeeHtml('data-test="client-financials"')->assertSeeHtml('data-test="client-payment-stats"')->assertSeeHtml('data-test="client-subscription-stats"')
        ->assertSee(['Invoices sent', 'Total invoiced', 'Total received', 'Average payment', 'Monthly recurring'])
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="invoiceSearch"');
    expect($stats($t))->toContain('$250.00');

    $t->set('invoiceSearch', 'INV-A')->assertSee('Search: INV-A');
    expect($stats($t))->toContain('$200.00')->not->toContain('$250.00');
});

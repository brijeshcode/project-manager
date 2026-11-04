<?php

use App\Livewire\Portal\Invoices as PortalInvoices;
use App\Livewire\Portal\Payments as PortalPayments;
use App\Livewire\Projects\Economics;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show;
use App\Livewire\Settings\System;
use App\Livewire\Tasks\Board;
use App\Models\Client;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
});

function b1User(string $role, array $attrs = []): User
{
    return User::factory()->create($attrs)->assignRole($role);
}

function b1Project(): Project
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);

    return Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
}

// ── breadcrumb ────────────────────────────────────────────────────────────────

it('project page breadcrumb shows Projects / Project / current tab', function (string $tab, ?string $label) {
    $p = b1Project();
    $html = Livewire::actingAs(b1User('super_admin'))->test(Show::class, ['project' => $p])->set('tab', $tab)->html();
    expect($html)->toContain('data-test="breadcrumb"')->not->toContain('&larr; Projects');
    preg_match('/<nav aria-label="Breadcrumb".*?<\/nav>/s', $html, $m);
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($m[0])));
    expect($text)->toBe($label ? "Projects / Site / $label" : 'Projects / Site');
    expect($m[0])->toContain('href="'.route('projects.index').'"');
    $label && expect($m[0])->toContain('href="'.route('projects.show', $p).'"');
})->with([
    'details' => ['details', null],
    'modules' => ['modules', 'Modules'],
    'sprint' => ['sprint', 'Sprint'],
    'resources' => ['resources', 'Resources'],
    'board' => ['board', 'Board'],
    'economics' => ['economics', 'Economics'],
]);

it('embedded board and economics have no own back header, standalone routes keep it', function () {
    $p = b1Project();
    $admin = b1User('super_admin');
    $page = Livewire::actingAs($admin)->test(Show::class, ['project' => $p]);
    $page->set('tab', 'board')->assertSeeLivewire(Board::class)->assertDontSee('Workspace')->assertDontSeeHtml('data-test="board-back"')->assertSeeHtml('data-test="board-scope"');
    $page->set('tab', 'economics')->assertSeeLivewire(Economics::class)->assertDontSeeHtml('data-test="economics-back"')->assertSee('Hours logged');

    $this->actingAs($admin)->get(route('projects.board', $p))->assertOk()->assertSee('Workspace')->assertSeeHtml('data-test="board-back"');
    $this->actingAs($admin)->get(route('projects.economics', $p))->assertOk()->assertSeeHtml('data-test="economics-back"')->assertSee('Economics');
});

// ── module filter labels and help ─────────────────────────────────────────────

it('module filters use the new labels and show a help line per filter', function () {
    $p = b1Project();
    Module::create(['project_id' => $p->id, 'name' => 'Checkout', 'position' => 1]);
    $page = Livewire::actingAs(b1User('super_admin'))->test(Show::class, ['project' => $p])->set('tab', 'modules');
    $page->assertSee('In active sprint')->assertSee('Not in sprint')->assertSee('Completed')->assertSee('All')
        ->assertSeeHtml('data-test="filter-sprint"')->assertSeeHtml('data-test="filter-backlog"');

    $help = fn ($c) => trim(preg_replace('/\s+/', ' ', strip_tags(preg_match('/data-test="filter-help">(.*?)<\/p>/s', $c->html(), $m) ? $m[1] : '')));
    expect($help($page))->toBe('Showing all modules; completed ones are in the section at the bottom');
    expect($help($page->set('filter', 'sprint')))->toBe('No active sprint — start one in the Sprint tab');
    expect($help($page->set('filter', 'backlog')))->toBe('Showing modules that are not completed and have no task in the active sprint');
    expect($help($page->set('filter', 'completed')))->toBe('Showing completed modules');

    $p->sprints()->create(['name' => 'Sprint 7', 'status' => 'active', 'starts_on' => today(), 'ends_on' => today()->addWeek()]);
    expect($help($page->set('filter', 'sprint')))->toBe('Showing modules that have at least one task in Sprint 7');

    $dev = b1User('developer', ['name' => 'Dana Dev']);
    $p->members()->attach($dev->id, ['role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);
    expect($help($page->set('assignedTo', (string) $dev->id)))->toContain('Dana Dev');
});

// ── settings storage ──────────────────────────────────────────────────────────

it('settings table has an indexed group column; existing keys keep working with sensible groups', function () {
    expect(Schema::hasColumn('settings', 'group'))->toBeTrue();
    Settings::set('local_currency', 'USD');
    Settings::set('custom', 'x');
    Settings::set('page_size', '25');
    expect(Settings::get('local_currency'))->toBe('USD')->and(Settings::get('missing', 'dflt'))->toBe('dflt')
        ->and(DB::table('settings')->where('key', 'local_currency')->value('group'))->toBe('currency')
        ->and(DB::table('settings')->where('key', 'custom')->value('group'))->toBe('general')
        ->and(DB::table('settings')->where('key', 'page_size')->value('group'))->toBe('system')
        ->and(Settings::group('system'))->toBe(['page_size' => '25']);
    Settings::set('custom', 'y', 'misc');
    expect(Settings::get('custom'))->toBe('y')->and(DB::table('settings')->where('key', 'custom')->value('group'))->toBe('misc');
});

it('settings reads are cached per request and writes refresh the cache', function () {
    Settings::set('k', 'one');
    DB::enableQueryLog();
    Settings::get('k');
    Settings::get('k');
    expect(count(DB::getQueryLog()))->toBe(0);
    Settings::set('k', 'two');
    expect(Settings::get('k'))->toBe('two');
});

it('defaults to page size 15 and full sidebar, and ignores invalid stored values', function () {
    expect(Settings::pageSize())->toBe(15)->and(Settings::sidebarLayout())->toBe('full');
    $this->seed(Database\Seeders\DatabaseSeeder::class);
    expect(Settings::get('page_size'))->toBe('15')->and(DB::table('settings')->where('key', 'sidebar_layout')->value('group'))->toBe('system');
    Settings::set('page_size', '7');
    Settings::set('sidebar_layout', 'huge');
    expect(Settings::pageSize())->toBe(15)->and(Settings::sidebarLayout())->toBe('full');
});

// ── nav ───────────────────────────────────────────────────────────────────────

it('Settings group lists items in order, gated by permission', function (string $role, array $items) {
    $r = $this->actingAs(b1User($role, $role === 'client' ? ['client_id' => Client::create(['name' => 'C', 'currency' => 'USD'])->id] : []))->get($role === 'client' ? '/portal' : '/dashboard')->assertOk();
    preg_match('/data-test="settings-nav">(.*?)<\/nav>/s', $r->getContent(), $m);
    preg_match_all('/<span class="sb-label">(.*?)<\/span>/s', $m[1], $labels);
    expect($labels[1])->toBe($items);
    $workspace = substr($r->getContent(), 0, strpos($r->getContent(), 'data-test="settings-nav"'));
    foreach (['Bank accounts', 'Currencies', 'Task types', 'Labels'] as $moved) {
        expect($workspace)->not->toContain('<span class="sb-label">'.$moved.'</span>');
    }
})->with([
    'admin' => ['super_admin', ['Currencies', 'Bank accounts', 'Task types', 'Labels', 'System', 'Profile']],
    'project manager' => ['project_manager', ['Profile']],
    'developer' => ['developer', ['Profile']],
    'client' => ['client', ['Profile']],
]);

// ── system page ───────────────────────────────────────────────────────────────

it('system page: 403 for non super admins, redirects guests', function (string $role) {
    $this->actingAs(b1User($role))->get(route('settings.system'))->assertForbidden();
    Livewire::actingAs(b1User($role))->test(System::class)->assertForbidden();
})->with(['project_manager', 'developer', 'client']);

it('system page saves valid values, rejects invalid ones and re-checks authorisation on save', function () {
    $this->get(route('settings.system'))->assertRedirect(route('login'));
    $admin = b1User('super_admin');
    $this->actingAs($admin)->get(route('settings.system'))->assertOk()->assertSee('Default page size')->assertSee('Default sidebar layout');

    Livewire::actingAs($admin)->test(System::class)->assertSet('pageSize', 15)->assertSet('sidebarLayout', 'full')
        ->set('pageSize', 50)->set('sidebarLayout', 'half')->call('save')->assertHasNoErrors()->assertDispatched('toast');
    expect(Settings::pageSize())->toBe(50)->and(Settings::sidebarLayout())->toBe('half')
        ->and(DB::table('settings')->where('key', 'page_size')->value('group'))->toBe('system');

    Livewire::actingAs($admin)->test(System::class)->set('pageSize', 7)->set('sidebarLayout', 'huge')->call('save')
        ->assertHasErrors(['pageSize', 'sidebarLayout']);
    expect(Settings::pageSize())->toBe(50)->and(Settings::sidebarLayout())->toBe('half');

    // a demoted user (mounted as admin earlier) cannot save
    $admin->syncRoles('developer');
    app('auth')->forgetGuards();
    Livewire::actingAs($admin->fresh())->test(System::class)->assertForbidden();
});

// ── sidebar layout rendering ─────────────────────────────────────────────────

it('renders the sidebar layout from the system default into the initial html', function () {
    $u = b1User('super_admin');
    $this->actingAs($u)->get('/dashboard')->assertSee('data-sidebar="full"', false);
    Settings::set('sidebar_layout', 'half');
    $this->actingAs($u)->get('/dashboard')->assertSee('data-sidebar="half"', false)->assertSee('data-test="sidebar-toggle"', false);
    Settings::set('sidebar_layout', 'mini');
    $this->actingAs($u)->get('/dashboard')->assertSee('data-sidebar="mini"', false)->assertSee('sb-topbar', false);
    expect(file_get_contents(resource_path('views/layouts/_theme-script.blade.php')))->toContain("localStorage.getItem('sidebar')");
});

// ── page size ─────────────────────────────────────────────────────────────────

it('page size setting changes rows per page on the clients and projects lists', function () {
    $admin = b1User('super_admin');
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    foreach (range(1, 12) as $i) {
        Client::create(['name' => sprintf('Client %02d', $i), 'currency' => 'USD']);
        Project::create(['client_id' => $c->id, 'name' => sprintf('Proj %02d', $i), 'status' => 'active']);
    }
    Settings::set('page_size', '10');
    Livewire::actingAs($admin)->test(\App\Livewire\Clients\Index::class)->assertSet('perPage', 10)->assertSee('Client 08')->assertDontSee('Client 12');
    Livewire::actingAs($admin)->test(ProjectsIndex::class)->assertSee('Proj 10')->assertDontSee('Proj 12');
    Settings::set('page_size', '25');
    Livewire::actingAs($admin)->test(\App\Livewire\Clients\Index::class)->assertSet('perPage', 25)->assertSee('Client 12');
    Livewire::actingAs($admin)->test(ProjectsIndex::class)->assertSee('Proj 12');
});

it('page size setting applies to the invoices and payments lists', function () {
    $admin = b1User('super_admin');
    $c = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    foreach (range(1, 12) as $i) {
        Payment::create(['client_id' => $c->id, 'amount_cents' => 1000 + $i, 'paid_on' => '2026-11-01', 'type' => 'project', 'reference' => sprintf('REF%02d', $i)]);
    }
    Settings::set('page_size', '10');
    expect(Livewire::actingAs($admin)->test(\App\Livewire\Payments\Index::class)->viewData('payments')->perPage())->toBe(10);
    Settings::set('page_size', '25');
    expect(Livewire::actingAs($admin)->test(\App\Livewire\Payments\Index::class)->viewData('payments')->perPage())->toBe(25);
    expect(Livewire::actingAs($admin)->test(\App\Livewire\Invoices\Index::class)->viewData('invoices')->perPage())->toBe(25);
});

it('page size setting applies to the portal invoices and payments lists', function () {
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $u = b1User('client', ['client_id' => $client->id]);
    foreach (range(1, 12) as $i) {
        Payment::create(['client_id' => $client->id, 'amount_cents' => 1000 + $i, 'paid_on' => '2026-11-01', 'type' => 'project']);
    }
    Settings::set('page_size', '10');
    expect(Livewire::actingAs($u)->test(PortalPayments::class)->viewData('payments')->perPage())->toBe(10);
    Settings::set('page_size', '100');
    expect(Livewire::actingAs($u)->test(PortalPayments::class)->viewData('payments')->perPage())->toBe(100);
    expect(Livewire::actingAs($u)->test(PortalInvoices::class)->viewData('invoices')->perPage())->toBe(100);
});

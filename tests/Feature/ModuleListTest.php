<?php

use App\Enums\AccessScope;
use App\Livewire\Modules\Show as ModuleShow;
use App\Livewire\Projects\Show;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
});

/** Project with a module, an admin, a member, a non-member, and two client users. */
function listSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $module = Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'position' => 1]);
    $mk = function (string $role, array $attrs = []) {
        $u = User::factory()->create($attrs);
        $u->assignRole($role);

        return $u;
    };
    $admin = $mk('super_admin');
    $member = $mk('developer');
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    $outsider = $mk('developer');
    $c1 = $mk('client', ['client_id' => $client->id]);
    $c2 = $mk('client', ['client_id' => $client->id]);
    $other = Client::create(['name' => 'Other', 'currency' => 'USD']);
    $foreign = $mk('client', ['client_id' => $other->id]);

    return compact('client', 'project', 'module', 'admin', 'member', 'outsider', 'c1', 'c2', 'foreign');
}

function listModPage(array $s, ?User $as = null)
{
    return Livewire::actingAs($as ?? $s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']]);
}

/** A module in the project whose one task sits in a column of the given stage (null = no tasks => pending). */
function listModule(array $s, string $name, ?string $stage = null, array $attrs = []): Module
{
    $m = Module::create(['project_id' => $s['project']->id, 'name' => $name, 'position' => Module::nextPosition($s['project']->id)] + $attrs);
    if ($stage) {
        Task::create(['module_id' => $m->id, 'creator_id' => $s['admin']->id, 'title' => "$name task", 'status' => 'published', 'scope' => 'in', 'position' => 1,
            'board_column_id' => $s['project']->columns()->where('stage', $stage)->first()->id]);
    }

    return $m;
}

/** Same mounted page, but the next request comes from a user who cannot update the project. */
function denied(array $s, $page)
{
    test()->actingAs($s['outsider']);

    return $page;
}

function listPage(array $s, ?User $as = null)
{
    return Livewire::actingAs($as ?? $s['admin'])->test(Show::class, ['project' => $s['project']])->set('tab', 'modules');
}

it('defaults module priority to Medium, validates it on the page and logs the change', function () {
    $s = listSetup();
    expect($s['module']->fresh()->priority)->toBe(1);

    listModPage($s)->call('openPanel', 'priority')->set('priority', 5)->call('savePanel')->assertHasErrors('priority');
    listModPage($s)->call('openPanel', 'priority')->set('priority', 2)->call('savePanel')->assertHasNoErrors();

    $row = $s['module']->activities()->where('field', 'priority')->first();
    expect($row)->old->toBe('Medium')->new->toBe('High');
    expect($row->summary())->toBe('changed the priority from Medium to High');
});

it('sets priority from the list, rejects bad values and non-managers', function () {
    $s = listSetup();
    listPage($s)->call('setModulePriority', $s['module']->id, 0);
    expect($s['module']->fresh()->priority)->toBe(0);
    expect($s['module']->activities()->where('field', 'priority')->first()->summary())->toBe('changed the priority from Medium to Low');

    listPage($s)->call('setModulePriority', $s['module']->id, 9)->assertStatus(422);
    denied($s, listPage($s))->call('setModulePriority', $s['module']->id, 2)->assertForbidden();
    expect($s['module']->fresh()->priority)->toBe(0);
});

it('persists drag order and keeps modules outside a filtered view in place', function () {
    $s = listSetup();
    $a = $s['module'];
    $b = listModule($s, 'B', 'active');
    $c = listModule($s, 'C');
    $d = listModule($s, 'D', 'active');

    listPage($s)->call('reorderModules', [$d->id, $b->id]); // b and d swap into their slots
    expect($s['project']->modules()->pluck('id')->all())->toBe([$a->id, $d->id, $c->id, $b->id]);

    listPage($s)->call('reorderModules', [$c->id, $a->id, $d->id, $b->id]);
    expect($s['project']->modules()->pluck('position')->all())->toBe([1, 2, 3, 4]);
    expect($s['project']->modules()->pluck('id')->all())->toBe([$c->id, $a->id, $d->id, $b->id]);
});

it('ignores foreign ids on reorder and refuses non-managers', function () {
    $s = listSetup();
    $b = listModule($s, 'B');
    $s2 = listSetup();
    $foreign = $s2['module'];

    listPage($s)->call('reorderModules', [$b->id, $foreign->id, $s['module']->id]);
    expect($foreign->fresh()->position)->toBe(1);
    expect($s['project']->modules()->pluck('id')->all())->toBe([$b->id, $s['module']->id]);

    denied($s, listPage($s))->call('reorderModules', [$s['module']->id, $b->id])->assertForbidden();
    Livewire::actingAs($s['outsider'])->test(Show::class, ['project' => $s['project']])->assertForbidden();
});

it('puts new modules at the end', function () {
    $s = listSetup();
    $b = listModule($s, 'B');
    expect($b->position)->toBe(2);
});

it('assigns from the list with logging, only active members, and toggles off', function () {
    $s = listSetup();
    listPage($s)->call('toggleModuleAssignee', $s['module']->id, $s['member']->id);
    expect($s['module']->assignees()->pluck('users.id')->all())->toBe([$s['member']->id]);
    $row = $s['module']->activities()->where('event', 'assigned')->first();
    expect($row)->new->toBe($s['member']->name);

    listPage($s)->call('toggleModuleAssignee', $s['module']->id, $s['outsider']->id)->assertForbidden();

    listPage($s)->call('toggleModuleAssignee', $s['module']->id, $s['member']->id);
    expect($s['module']->assignees()->count())->toBe(0);
    expect($s['module']->activities()->where('event', 'unassigned')->exists())->toBeTrue();

    denied($s, listPage($s))->call('toggleModuleAssignee', $s['module']->id, $s['member']->id)->assertForbidden();
});

it('filters Sprint, Backlog, Completed and All by active-sprint membership', function () {
    $s = listSetup();               // Checkout: no tasks => backlog
    $work = listModule($s, 'Working', 'active');
    $rev = listModule($s, 'Reviewing', 'review');
    $hold = listModule($s, 'Holding', 'active', ['status_override' => 'on_hold']);
    $done = listModule($s, 'Finished', 'done');
    $sprint = $s['project']->sprints()->create(['name' => 'Sprint 1', 'status' => 'active', 'starts_on' => today(), 'ends_on' => today()->addDays(6)]);
    $planned = $s['project']->sprints()->create(['name' => 'Sprint 2', 'status' => 'planned', 'starts_on' => today()->addWeek(), 'ends_on' => today()->addDays(13)]);
    $work->tasks()->first()->update(['sprint_id' => $sprint->id]);
    $hold->tasks()->first()->update(['sprint_id' => $sprint->id]);
    $rev->tasks()->first()->update(['sprint_id' => $planned->id]); // a planned sprint does not count

    $names = fn ($c) => $c->viewData('mainRows')->pluck('m.name')->sort()->values()->all();

    $p = listPage($s);
    expect($names($p))->toBe(['Checkout', 'Holding', 'Reviewing', 'Working']); // All: completed leaves the main list
    expect($p->viewData('doneRows')->pluck('m.name')->all())->toBe(['Finished']);

    expect($names($p->set('filter', 'sprint')))->toBe(['Holding', 'Working']);
    expect($names($p->set('filter', 'backlog')))->toBe(['Checkout', 'Reviewing']);
    $p->set('filter', 'completed');
    expect($names($p))->toBe(['Finished']);
    expect($p->viewData('doneRows'))->toHaveCount(0); // shown expanded in the main area instead
});

it('filters by user: assigned to the module or to any task in it', function () {
    $s = listSetup();
    $direct = listModule($s, 'Direct');
    $viaTask = listModule($s, 'ViaTask', 'active');
    $none = listModule($s, 'None');
    $direct->assignees()->attach($s['member']->id);
    $viaTask->tasks()->first()->assignees()->attach($s['member']->id);

    $p = listPage($s)->set('assignedTo', (string) $s['member']->id);
    expect($p->viewData('mainRows')->pluck('m.name')->sort()->values()->all())->toBe(['Direct', 'ViaTask']);
    $p->set('assignedTo', '');
    expect($p->viewData('mainRows'))->toHaveCount(4);
});

it('shows an empty state when filters match nothing', function () {
    $s = listSetup();
    $s['project']->sprints()->create(['name' => 'Sprint 1', 'status' => 'active', 'starts_on' => today(), 'ends_on' => today()->addDays(6)]);
    listPage($s)->set('filter', 'sprint')->assertSee('No modules match');
});

it('hints "No active sprint" on the Sprint filter and keeps everything in Backlog when none is active', function () {
    $s = listSetup();
    listPage($s)->set('filter', 'sprint')->assertSee('No active sprint')->assertDontSee('Checkout');
    expect(listPage($s)->set('filter', 'backlog')->viewData('mainRows')->pluck('m.name')->all())->toBe(['Checkout']);
});

it('collapses completed modules into a counted section, including manual overrides', function () {
    $s = listSetup();
    listModule($s, 'Derived', 'done');
    listModule($s, 'Manual', 'active', ['status_override' => 'completed']);

    $p = listPage($s);
    $p->assertSee('Completed (2)')->assertSeeHtml('x-data="{ done: false }"');
    expect($p->viewData('mainRows')->pluck('m.name')->all())->toBe(['Checkout']);
    expect($p->viewData('doneRows')->pluck('m.name')->sort()->values()->all())->toBe(['Derived', 'Manual']);
});

it('moves a module back to the main list when it regresses or is reopened', function () {
    $s = listSetup();
    $derived = listModule($s, 'Derived', 'done');
    $manual = listModule($s, 'Manual', 'active', ['status_override' => 'completed']);

    $derived->tasks()->first()->update(['board_column_id' => $s['project']->columns()->where('stage', 'active')->first()->id]);
    $manual->setStatusOverride(null);

    $p = listPage($s);
    expect($p->viewData('doneRows'))->toHaveCount(0);
    expect($p->viewData('mainRows'))->toHaveCount(3);
    $p->assertDontSee('Completed (');
});

it('keeps the filters in the URL', function () {
    $s = listSetup();
    $p = listPage($s)->set('filter', 'backlog')->set('assignedTo', (string) $s['member']->id);
    $p->assertSetStrict('filter', 'backlog');
    expect(Livewire::actingAs($s['admin'])->withQueryParams(['tab' => 'modules', 'filter' => 'completed', 'user' => (string) $s['member']->id])
        ->test(Show::class, ['project' => $s['project']])->get('filter'))->toBe('completed');
});

it('treats an unknown filter as All', function () {
    $s = listSetup();
    listPage($s)->set('filter', 'nonsense')->assertOk()->assertSee('Checkout');
});

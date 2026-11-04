<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Projects\Show;
use App\Livewire\Sprints\Panel;
use App\Livewire\Tasks\Board;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Sprint;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
});

/** Project, two modules, admin, an active member, an inactive member, an outsider. */
function brSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $m1 = Module::create(['project_id' => $project->id, 'name' => 'Alpha', 'position' => 1]);
    $m2 = Module::create(['project_id' => $project->id, 'name' => 'Beta', 'position' => 2]);
    $mk = function (string $role, bool $member = true, bool $active = true) use ($project) {
        $u = User::factory()->create();
        $u->assignRole($role);
        $member && ProjectMember::create(['project_id' => $project->id, 'user_id' => $u->id, 'role_on_project' => $role, 'access_scope' => AccessScope::All, 'active' => $active]);

        return $u;
    };
    $admin = $mk('super_admin', false);
    $member = $mk('developer');
    $inactive = $mk('developer', true, false);
    $outsider = $mk('developer', false);

    return compact('client', 'project', 'm1', 'm2', 'admin', 'member', 'inactive', 'outsider');
}

function brTask(array $s, Module $m, string $title, array $a = []): Task
{
    return Task::create(array_merge(['module_id' => $m->id, 'creator_id' => $s['admin']->id, 'title' => $title, 'status' => TaskStatus::Published, 'priority' => 0, 'scope' => TaskScope::In, 'position' => 1], $a));
}

function brBoard(array $s, ?User $as = null, array $q = [])
{
    return Livewire::actingAs($as ?? $s['admin'])->withQueryParams($q)->test(Board::class, ['project' => $s['project']]);
}

function brTitles($c): array
{
    return $c->instance()->columns()->flatten()->pluck('title')->sort()->values()->all();
}

// ── assignee component ───────────────────────────────────────────────────────

it('renders the tooltip name, aria-label and a plus only when candidates are given', function () {
    $s = brSetup();
    $s['member']->update(['name' => 'Ada Lovelace']);
    $users = collect([$s['member']]);
    $edit = Blade::render('<x-ui.assignees :users="$u" :candidates="$c" method="toggleTaskAssignee" :id="5" />', ['u' => $users, 'c' => collect([$s['member']])]);
    expect($edit)->toContain('data-tip="Ada Lovelace"')->toContain('aria-label="Ada Lovelace"')->toContain('data-test="assign-btn"')->toContain('wire:change="toggleTaskAssignee(5, '.$s['member']->id.')"');
    $ro = Blade::render('<x-ui.assignees :users="$u" />', ['u' => $users]);
    expect($ro)->toContain('data-tip="Ada Lovelace"')->not->toContain('assign-btn');
});

it('shows the plus on the board to project members and keeps outsiders out', function () {
    $s = brSetup();
    brTask($s, $s['m1'], 'T1');
    brBoard($s)->assertSeeHtml('data-test="assign-btn"');
    brBoard($s, $s['member'])->assertSeeHtml('data-test="assign-btn"');
    brBoard($s, $s['outsider'])->assertForbidden();
});

// ── assign actions ───────────────────────────────────────────────────────────

it('assigns and unassigns a task from the board with logging', function () {
    $s = brSetup();
    $t = brTask($s, $s['m1'], 'T1');
    brBoard($s)->call('toggleTaskAssignee', $t->id, $s['member']->id);
    expect($t->assignees()->pluck('users.id')->all())->toBe([$s['member']->id]);
    expect($t->activities()->where('event', 'assigned')->first())->new->toBe($s['member']->name);
    brBoard($s)->call('toggleTaskAssignee', $t->id, $s['member']->id);
    expect($t->assignees()->count())->toBe(0);
    expect($t->activities()->where('event', 'unassigned')->exists())->toBeTrue();
});

it('ignores non-members, inactive members and foreign tasks; refuses outsiders', function () {
    $s = brSetup();
    $t = brTask($s, $s['m1'], 'T1');
    brBoard($s)->call('toggleTaskAssignee', $t->id, $s['outsider']->id)->call('toggleTaskAssignee', $t->id, $s['inactive']->id);
    expect($t->assignees()->count())->toBe(0);

    $other = Project::create(['client_id' => $s['client']->id, 'name' => 'Other', 'status' => 'active']);
    $om = Module::create(['project_id' => $other->id, 'name' => 'X', 'position' => 1]);
    $foreign = brTask($s, $om, 'F');
    brBoard($s)->call('toggleTaskAssignee', $foreign->id, $s['member']->id);
    expect($foreign->assignees()->count())->toBe(0);

    brBoard($s, $s['outsider'])->assertForbidden();
    expect($t->assignees()->count())->toBe(0);
});

it('assigns tasks from the Modules tab and the sprint plan with the same rules', function () {
    $s = brSetup();
    $t = brTask($s, $s['m1'], 'T1');
    Livewire::actingAs($s['admin'])->test(Show::class, ['project' => $s['project']])
        ->call('toggleTaskAssignee', $t->id, $s['member']->id)->call('toggleTaskAssignee', $t->id, $s['outsider']->id);
    expect($t->assignees()->pluck('users.id')->all())->toBe([$s['member']->id]);
    expect($t->activities()->where('event', 'assigned')->count())->toBe(1);
    Livewire::actingAs($s['outsider'])->test(Show::class, ['project' => $s['project']])->assertForbidden();

    Livewire::actingAs($s['admin'])->test(Panel::class, ['project' => $s['project']])->call('toggleTaskAssignee', $t->id, $s['member']->id);
    expect($t->assignees()->count())->toBe(0);
    Livewire::actingAs($s['outsider'])->test(Panel::class, ['project' => $s['project']])->assertForbidden();
});

it('shows the plus on module-tab task rows and sprint rows for members', function () {
    $s = brSetup();
    brTask($s, $s['m1'], 'T1');
    Livewire::actingAs($s['admin'])->test(Show::class, ['project' => $s['project'], 'tab' => 'modules'])->assertSeeHtml('wire:change="toggleTaskAssignee(');
    Livewire::actingAs($s['admin'])->test(Panel::class, ['project' => $s['project']])->assertSeeHtml('wire:change="toggleTaskAssignee(');
});

// ── filters ──────────────────────────────────────────────────────────────────

it('filters by several modules and keeps the legacy single module param', function () {
    $s = brSetup();
    $m3 = Module::create(['project_id' => $s['project']->id, 'name' => 'Gamma', 'position' => 3]);
    brTask($s, $s['m1'], 'A1');
    brTask($s, $s['m2'], 'B1');
    brTask($s, $m3, 'C1');
    expect(brTitles(brBoard($s)->set('moduleIds', [(string) $s['m1']->id, (string) $m3->id])))->toBe(['A1', 'C1']);
    expect(brTitles(brBoard($s, null, ['module' => $s['m2']->id])))->toBe(['B1']);
    $c = brBoard($s)->set('filterModule', $s['m1']->id);
    expect(brTitles($c))->toBe(['A1'])->and($c->get('moduleIds'))->toBe([(string) $s['m1']->id]);
});

it('filters by assignee including unassigned, type, label, priority and search', function () {
    $s = brSetup();
    $base = TaskType::create(['name' => 'Basex', 'color' => '#00ff00', 'position' => 1]);
    $bug = TaskType::create(['name' => 'Bugx', 'color' => '#ff0000', 'position' => 99]);
    $lab = Label::create(['name' => 'Urgentx', 'color' => '#ff0000']);
    $a = brTask($s, $s['m1'], 'Fix login', ['priority' => 2, 'task_type_id' => $bug->id]);
    $a->assignees()->attach($s['member']->id);
    $a->labels()->attach($lab->id);
    brTask($s, $s['m1'], 'Write docs', ['priority' => 0, 'task_type_id' => $base->id]);
    $c = brTask($s, $s['m2'], 'Deploy', ['priority' => 1, 'task_type_id' => $base->id]);
    $c->assignees()->attach($s['admin']->id);

    expect(brTitles(brBoard($s)->set('assigneeIds', [(string) $s['member']->id])))->toBe(['Fix login']);
    expect(brTitles(brBoard($s)->set('assigneeIds', ['none'])))->toBe(['Write docs']);
    expect(brTitles(brBoard($s)->set('assigneeIds', ['none', (string) $s['admin']->id])))->toBe(['Deploy', 'Write docs']);
    expect(brTitles(brBoard($s)->set('typeIds', [(string) $bug->id])))->toBe(['Fix login']);
    expect(brTitles(brBoard($s)->set('labelIds', [(string) $lab->id])))->toBe(['Fix login']);
    expect(brTitles(brBoard($s)->set('prios', ['2', '1'])))->toBe(['Deploy', 'Fix login']);
    expect(brTitles(brBoard($s)->set('search', 'docs')))->toBe(['Write docs']);
    expect(brTitles(brBoard($s)->set('search', '%')))->toBe([]);
    // every active filter must match
    expect(brTitles(brBoard($s)->set('prios', ['2'])->set('moduleIds', [(string) $s['m2']->id])))->toBe([]);
});

it('restores every filter from the URL, removes a chip and clears all', function () {
    $s = brSetup();
    brTask($s, $s['m1'], 'A1');
    brTask($s, $s['m2'], 'B1', ['priority' => 2]);
    $q = ['q' => 'B', 'modules' => [(string) $s['m2']->id], 'prios' => ['2'], 'assignees' => ['none'], 'group' => '1'];
    $c = brBoard($s, null, $q);
    expect($c->get('search'))->toBe('B')->and($c->get('moduleIds'))->toBe([(string) $s['m2']->id])->and($c->get('prios'))->toBe(['2'])->and($c->get('assigneeIds'))->toBe(['none'])->and($c->get('grouped'))->toBeTrue();
    $c->assertSeeHtml('data-test="filter-chips"')->assertSeeHtml('data-test="clear-filters"');
    $c->call('removeFilter', 'prios', '2')->assertSet('prios', []);
    $c->call('clearFilters')->assertSet('search', '')->assertSet('moduleIds', [])->assertSet('assigneeIds', [])->assertDontSeeHtml('data-test="clear-filters"');
});

it('still groups by module and honours the module filter in swimlanes', function () {
    $s = brSetup();
    brTask($s, $s['m1'], 'A1');
    brTask($s, $s['m2'], 'B1');
    brBoard($s)->set('grouped', true)->assertSee('Alpha')->assertSee('Beta')->assertSee('A1')->assertSee('B1')
        ->set('moduleIds', [(string) $s['m2']->id])->assertDontSee('A1')->assertSee('B1');
});

// ── card content ─────────────────────────────────────────────────────────────

it('renders priority, deadline chip, counts, label dots, client icon, edit link and the title link', function () {
    $s = brSetup();
    $lab = Label::create(['name' => 'Urgentx', 'color' => '#ff0000']);
    $t = brTask($s, $s['m1'], 'Carded', ['priority' => 2, 'deadline' => today()->addDay(), 'client_visible' => true]);
    $t->labels()->attach($lab->id);
    Subtask::create(['task_id' => $t->id, 'title' => 's1', 'is_done' => true]);
    Subtask::create(['task_id' => $t->id, 'title' => 's2']);
    $t->checklistItems()->create(['title' => 'c1', 'is_done' => true, 'position' => 1]);
    $html = brBoard($s)->html();
    expect($html)->toContain('data-priority="2"')->toContain('data-state="soon"')->toContain('data-test="card-deadline"')
        ->toContain('data-test="card-subtasks"')->toContain('1/2')->toContain('data-test="card-checklist"')
        ->toContain('data-label="Urgentx"')->toContain('data-test="card-client"')->toContain('data-test="card-edit"')
        ->toContain('data-test="card-link"')->toContain('draggable="false"')->toContain('line-clamp-2');
    $t->update(['deadline' => null]);
    expect(brBoard($s)->html())->not->toContain('data-state=');
});

it('persists a board move', function () {
    $s = brSetup();
    $t = brTask($s, $s['m1'], 'T1');
    $cols = $s['project']->columns()->get();
    brBoard($s)->call('taskMoved', $t->id, $cols[1]->id, 1, [$t->id]);
    expect($t->fresh()->board_column_id)->toBe($cols[1]->id);
});

it('opens on the active sprint by default, with all one click or one URL param away', function () {
    $s = brSetup();
    $sp = Sprint::create(['project_id' => $s['project']->id, 'name' => 'S1', 'status' => 'active', 'starts_on' => today(), 'ends_on' => today()->addWeek()]);
    brTask($s, $s['m1'], 'Sprinted', ['sprint_id' => $sp->id]);
    brTask($s, $s['m1'], 'Loose');
    $c = brBoard($s);
    expect($c->get('scope'))->toBe('sprint');
    $c->assertSee('Sprinted')->assertDontSee('Loose')->assertDontSeeHtml('data-test="board-fallback"');
    $c->set('scope', 'all')->assertSee('Loose');
    brBoard($s, null, ['scope' => 'all'])->assertSee('Loose')->assertSee('Sprinted');
});

it('falls back to all tasks with a hint when no sprint is active', function () {
    $s = brSetup();
    brTask($s, $s['m1'], 'Loose');
    $c = brBoard($s);
    expect($c->get('scope'))->toBe('all');
    $c->assertSee('Loose')->assertSee('No active sprint, showing all tasks.');
});

<?php

use App\Livewire\Portal\ProjectView;
use App\Livewire\Projects\Show;
use App\Livewire\Sprints\Panel;
use App\Livewire\Tasks\Board;
use App\Livewire\Tasks\Show as TaskShow;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
});

function spSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $m1 = Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'position' => 1]);
    $m2 = Module::create(['project_id' => $project->id, 'name' => 'Catalog', 'position' => 2]);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $dev->id, 'role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);
    $outsider = User::factory()->create();
    $outsider->assignRole('developer');

    return compact('client', 'project', 'm1', 'm2', 'admin', 'dev', 'outsider');
}

function spTask(array $s, Module $m, string $title, ?string $stage = null, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id' => $m->id, 'creator_id' => $s['admin']->id, 'title' => $title, 'status' => 'published', 'scope' => 'in',
        'estimate_hours' => 2, 'board_column_id' => $stage ? $s['project']->columns()->where('stage', $stage)->first()->id : null,
    ], $attrs));
}

function spSprint(array $s, string $status = 'planned', array $attrs = []): Sprint
{
    static $n = 0;
    $n++;

    return $s['project']->sprints()->create($attrs + ['name' => 'Sprint '.$n, 'status' => $status, 'starts_on' => today()->addWeeks($n), 'ends_on' => today()->addWeeks($n)->addDays(6)]);
}

function spPage(array $s, ?User $as = null)
{
    return Livewire::actingAs($as ?? $s['admin'])->test(Panel::class, ['project' => $s['project']]);
}

function spTitles($rows): array
{
    return $rows->pluck('title')->all();
}

// ── model ─────────────────────────────────────────────────────────────────────

it('scopes sprints by status and computes days left', function () {
    $s = spSetup();
    $a = spSprint($s, 'active', ['starts_on' => today()->subDays(2), 'ends_on' => today()->addDays(4)]);
    $p = spSprint($s, 'planned');
    $c = spSprint($s, 'completed');

    expect(Sprint::active()->pluck('id')->all())->toBe([$a->id])
        ->and(Sprint::planned()->pluck('id')->all())->toBe([$p->id])
        ->and(Sprint::completed()->pluck('id')->all())->toBe([$c->id])
        ->and($a->isActive())->toBeTrue()->and($p->isActive())->toBeFalse()
        ->and($a->daysLeft())->toBe(4)
        ->and($s['project']->sprints)->toHaveCount(3);

    $a->ends_on = today()->subDay();
    expect($a->daysLeft())->toBe(-1);

    $t = spTask($s, $s['m1'], 'T', null, ['sprint_id' => $a->id]);
    expect($t->sprint->is($a))->toBeTrue()->and($a->tasks)->toHaveCount(1);
});

it('names the next sprint after the highest number', function () {
    $s = spSetup();
    expect(Sprint::nextName($s['project']->id))->toBe('Sprint 1');
    spSprint($s, 'completed', ['name' => 'Sprint 4']);
    spSprint($s, 'planned', ['name' => 'Spike']);
    expect(Sprint::nextName($s['project']->id))->toBe('Sprint 5');
});

it('deletes a sprint with nullOnDelete on its tasks', function () {
    $s = spSetup();
    $sp = spSprint($s, 'completed');
    $t = spTask($s, $s['m1'], 'T', null, ['sprint_id' => $sp->id]);
    $sp->delete();
    expect($t->fresh()->sprint_id)->toBeNull();
});

// ── create / start / edit / delete ───────────────────────────────────────────

it('plans a sprint with a default name and a 1 or 2 week length', function () {
    $s = spSetup();
    $p = spPage($s)->call('openCreate')->assertSet('name', 'Sprint 1')->assertSet('weeks', 1);
    $p->set('startsOn', '2026-11-09')->set('goal', 'Ship it')->call('saveForm')->assertHasNoErrors()->assertSet('formMode', null);
    $sp = $s['project']->sprints()->first();
    expect($sp)->name->toBe('Sprint 1')->status->toBe('planned')->goal->toBe('Ship it')
        ->and($sp->starts_on->toDateString())->toBe('2026-11-09')->and($sp->ends_on->toDateString())->toBe('2026-11-15');

    spPage($s)->call('openCreate')->set('weeks', 2)->set('startsOn', '2026-11-16')->call('saveForm');
    expect($s['project']->sprints()->reorder('id', 'desc')->first())->name->toBe('Sprint 2')
        ->and($s['project']->sprints()->reorder('id', 'desc')->first()->ends_on->toDateString())->toBe('2026-11-29');
});

it('rejects bad length, empty name and a manager-less user', function () {
    $s = spSetup();
    spPage($s)->call('openCreate')->set('weeks', 3)->call('saveForm')->assertHasErrors('weeks');
    spPage($s)->call('openCreate')->set('name', '')->call('saveForm')->assertHasErrors('name');
    expect($s['project']->sprints()->count())->toBe(0);

    $p = spPage($s)->call('openCreate');
    test()->actingAs($s['outsider']);
    $p->call('saveForm')->assertForbidden();
});

it('lets a plain outsider neither view nor change the tab', function () {
    $s = spSetup();
    spPage($s, $s['outsider'])->assertForbidden();
});

it('starts a planned sprint with editable dates, validated to 1-2 weeks', function () {
    $s = spSetup();
    $sp = spSprint($s);
    spPage($s)->call('openStart', $sp->id)->assertSet('formMode', 'start')
        ->set('endsOn', $sp->starts_on->copy()->subDay()->toDateString())->call('saveForm')->assertHasErrors('endsOn');
    spPage($s)->call('openStart', $sp->id)
        ->set('endsOn', $sp->starts_on->copy()->addDays(30)->toDateString())->call('saveForm')->assertHasErrors('endsOn');
    expect($sp->fresh()->status)->toBe('planned');

    spPage($s)->call('openStart', $sp->id)->set('endsOn', $sp->starts_on->copy()->addDays(13)->toDateString())->call('saveForm')->assertHasNoErrors();
    expect($sp->fresh())->status->toBe('active')->and($sp->fresh()->ends_on->toDateString())->toBe($sp->starts_on->copy()->addDays(13)->toDateString());
});

it('never has two active sprints', function () {
    $s = spSetup();
    spSprint($s, 'active');
    $b = spSprint($s);
    spPage($s)->call('openStart', $b->id)->call('saveForm')->assertHasErrors('sprint');
    expect($b->fresh()->status)->toBe('planned')->and(Sprint::active()->count())->toBe(1);
    expect(fn () => $b->start())->toThrow(Illuminate\Validation\ValidationException::class);
    // the Start button is not even offered while one is active
    spPage($s)->assertDontSeeHtml('data-test="start-sprint"');
});

it('edits name, goal and dates of a sprint', function () {
    $s = spSetup();
    $sp = spSprint($s);
    spPage($s)->call('openEdit', $sp->id)->set('name', 'Alpha')->set('goal', 'G')->call('saveForm')->assertHasNoErrors();
    expect($sp->fresh())->name->toBe('Alpha')->goal->toBe('G');
    spPage($s)->call('openEdit', $sp->id)->set('endsOn', $sp->starts_on->toDateString())->call('saveForm')->assertHasErrors('endsOn');
});

it('deletes a planned sprint, returning its tasks to the backlog; active and completed cannot be deleted', function () {
    $s = spSetup();
    $planned = spSprint($s);
    $active = spSprint($s, 'active');
    $done = spSprint($s, 'completed');
    $t = spTask($s, $s['m1'], 'T', null, ['sprint_id' => $planned->id]);

    spPage($s)->call('deleteSprint', $planned->id);
    expect(Sprint::find($planned->id))->toBeNull()->and($t->fresh()->sprint_id)->toBeNull();
    expect(fn () => spPage($s)->call('deleteSprint', $active->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(Sprint::find($active->id))->not->toBeNull()->and(Sprint::find($done->id))->not->toBeNull();
});

// ── completing ────────────────────────────────────────────────────────────────

it('completes a sprint: unfinished move to the next planned sprint, done tasks stay', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $next = spSprint($s);
    $done = spTask($s, $s['m1'], 'Done one', 'done', ['sprint_id' => $a->id, 'estimate_hours' => 3]);
    $open = spTask($s, $s['m1'], 'Open one', 'active', ['sprint_id' => $a->id, 'estimate_hours' => 5]);

    $p = spPage($s)->call('openComplete', $a->id)->assertSee('1 of 2 tasks')->assertSeeHtml('data-test="sum-planned"');
    expect($p->html())->toContain('8h')->toContain('3h');
    $p->set('rollover', 'next')->call('completeSprint');

    expect($a->fresh())->status->toBe('completed')->and($a->fresh()->completed_at)->not->toBeNull();
    expect($done->fresh()->sprint_id)->toBe($a->id)->and($open->fresh()->sprint_id)->toBe($next->id);
    expect(Sprint::count())->toBe(2);

    // the next sprint can now be started
    spPage($s)->call('openStart', $next->id)->call('saveForm')->assertHasNoErrors();
    expect($next->fresh()->status)->toBe('active');
});

it('creates the next sprint when none is planned, named and dated after the completed one', function () {
    $s = spSetup();
    $a = spSprint($s, 'active', ['name' => 'Sprint 7', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-08']);
    $open = spTask($s, $s['m1'], 'Open', 'active', ['sprint_id' => $a->id]);

    spPage($s)->call('openComplete', $a->id)->call('completeSprint');
    $new = $open->fresh()->sprint;
    expect($new)->name->toBe('Sprint 8')->status->toBe('planned')
        ->and($new->starts_on->toDateString())->toBe('2026-11-09')->and($new->ends_on->toDateString())->toBe('2026-11-15');
});

it('sends unfinished tasks back to the backlog on request, creating nothing', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $open = spTask($s, $s['m1'], 'Open', 'active', ['sprint_id' => $a->id]);

    spPage($s)->call('openComplete', $a->id)->set('rollover', 'backlog')->call('completeSprint');
    expect($open->fresh()->sprint_id)->toBeNull()->and(Sprint::count())->toBe(1);
    spPage($s)->assertViewHas('backlog', fn ($b) => spTitles($b) === ['Open']);
});

it('rejects an unknown rollover choice and completing a planned sprint', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $p = spSprint($s);
    spPage($s)->call('openComplete', $a->id)->set('rollover', 'nope')->call('completeSprint')->assertStatus(422);
    expect(fn () => spPage($s)->call('openComplete', $p->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('shows completed sprints in a collapsed list with their done tasks', function () {
    $s = spSetup();
    $c = spSprint($s, 'completed', ['name' => 'Sprint Zero']);
    spTask($s, $s['m1'], 'Shipped', 'done', ['sprint_id' => $c->id]);
    spPage($s)->assertSee('Past sprints (1)')->assertSee('Sprint Zero')->assertSee('1 done')
        ->assertDontSee('Shipped')->call('togglePast', $c->id)->assertSee('Shipped');
});

// ── plan lists ────────────────────────────────────────────────────────────────

it('builds the backlog from open unsprinted counted tasks only', function () {
    $s = spSetup();
    $sp = spSprint($s, 'active');
    spTask($s, $s['m1'], 'Backlog one', 'not_started');
    spTask($s, $s['m1'], 'Finished', 'done');
    spTask($s, $s['m1'], 'In sprint', 'active', ['sprint_id' => $sp->id]);
    spTask($s, $s['m2'], 'An idea', 'not_started', ['is_idea' => true]);
    spTask($s, $s['m2'], 'Out of scope', 'not_started', ['scope' => 'out']);
    spTask($s, $s['m2'], 'A draft', 'not_started', ['status' => 'draft']);

    spPage($s)->assertViewHas('backlog', fn ($b) => spTitles($b) === ['Backlog one'])
        ->assertViewHas('activeTasks', fn ($b) => spTitles($b) === ['In sprint']);
});

it('orders list rows by module then task position', function () {
    $s = spSetup();
    spTask($s, $s['m2'], 'Cat 1', 'not_started');
    spTask($s, $s['m1'], 'Chk 1', 'not_started');
    spTask($s, $s['m1'], 'Chk 2', 'not_started');
    spPage($s)->assertViewHas('backlog', fn ($b) => spTitles($b) === ['Chk 1', 'Chk 2', 'Cat 1']);
});

it('shows list counts, hours and done/total for the active sprint', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    spTask($s, $s['m1'], 'A', 'done', ['sprint_id' => $a->id, 'estimate_hours' => 1.5]);
    spTask($s, $s['m1'], 'B', 'active', ['sprint_id' => $a->id, 'estimate_hours' => 2]);
    spTask($s, $s['m1'], 'C', 'not_started', ['estimate_hours' => 4]);
    $p = spPage($s);
    $p->assertSeeHtml('data-test="list-count"')->assertSee('1/2 done')->assertSee('3.5h')->assertSee('4h');
});

it('shows empty states with guidance', function () {
    $s = spSetup();
    spPage($s)->assertSee('No active sprint')->assertSee('Start a sprint')->assertSee('No upcoming sprint planned')->assertSee('The backlog is empty');
});

it('filters by user, type and label, and keeps them in the URL', function () {
    $s = spSetup();
    TaskType::firstOrCreate(['name' => 'Task'], ['color' => '#6b7280', 'is_system' => true]); // the default type
    $bug = TaskType::firstOrCreate(['name' => 'Bug'], ['color' => '#ef4444']);
    $lab = Label::create(['name' => 'Blocked', 'color' => '#111111']);
    $mine = spTask($s, $s['m1'], 'Mine', 'not_started');
    $mine->assignees()->attach($s['dev']->id);
    $typed = spTask($s, $s['m1'], 'Typed', 'not_started', ['task_type_id' => $bug->id]);
    $labelled = spTask($s, $s['m1'], 'Labelled', 'not_started');
    $labelled->labels()->attach($lab->id);

    $p = spPage($s);
    $p->set('user', (string) $s['dev']->id)->assertViewHas('backlog', fn ($b) => spTitles($b) === ['Mine']);
    $p->set('user', '')->set('type', (string) $bug->id)->assertViewHas('backlog', fn ($b) => spTitles($b) === ['Typed']);
    $p->set('type', '')->set('label', (string) $lab->id)->assertViewHas('backlog', fn ($b) => spTitles($b) === ['Labelled']);
    $p->assertSet('label', (string) $lab->id);
    expect(Livewire::actingAs($s['admin'])->withQueryParams(['spUser' => (string) $s['dev']->id])->test(Panel::class, ['project' => $s['project']]))
        ->user->toBe((string) $s['dev']->id);
});

// ── drag and drop ─────────────────────────────────────────────────────────────

it('moves a task between backlog, planned and active lists', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $n = spSprint($s);
    $t = spTask($s, $s['m1'], 'T', 'not_started');

    $p = spPage($s)->call('moveTask', $t->id, $a->id, [$t->id]);
    expect($t->fresh()->sprint_id)->toBe($a->id);
    $p->call('moveTask', $t->id, $n->id, [$t->id]);
    expect($t->fresh()->sprint_id)->toBe($n->id);
    $p->call('moveTask', $t->id, 0, [$t->id]);
    expect($t->fresh()->sprint_id)->toBeNull();
});

it('reorders inside a list through the one per-module order, leaving other modules alone', function () {
    $s = spSetup();
    $a1 = spTask($s, $s['m1'], 'A1', 'not_started');
    $a2 = spTask($s, $s['m1'], 'A2', 'not_started');
    $a3 = spTask($s, $s['m1'], 'A3', 'not_started');
    $b1 = spTask($s, $s['m2'], 'B1', 'not_started');
    $b2 = spTask($s, $s['m2'], 'B2', 'not_started');

    spPage($s)->call('reorderList', [$a3->id, $b2->id, $a1->id, $b1->id, $a2->id]);
    $order = fn ($m) => Task::where('module_id', $m->id)->orderBy('position')->pluck('title')->all();
    expect($order($s['m1']))->toBe(['A3', 'A1', 'A2'])->and($order($s['m2']))->toBe(['B2', 'B1']);
    expect(Task::where('module_id', $s['m1']->id)->pluck('position')->sort()->values()->all())->toBe([1, 2, 3]);
});

it('a filtered list reorder only takes over the slots of the visible tasks', function () {
    $s = spSetup();
    $t1 = spTask($s, $s['m1'], 'T1', 'not_started');
    $t2 = spTask($s, $s['m1'], 'T2', 'not_started');
    $t3 = spTask($s, $s['m1'], 'T3', 'not_started');
    spPage($s)->call('reorderList', [$t3->id, $t1->id]);
    expect(Task::where('module_id', $s['m1']->id)->orderBy('position')->pluck('title')->all())->toBe(['T3', 'T2', 'T1']);
});

it('drops into a list at a position', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $x = spTask($s, $s['m1'], 'X', 'active', ['sprint_id' => $a->id]);
    $y = spTask($s, $s['m1'], 'Y', 'not_started');
    spPage($s)->call('moveTask', $y->id, $a->id, [$y->id, $x->id]);
    expect(Task::where('module_id', $s['m1']->id)->orderBy('position')->pluck('title')->all())->toBe(['Y', 'X'])
        ->and($y->fresh()->sprint_id)->toBe($a->id);
});

it('ignores foreign-project tasks and sprints, done tasks and completed sprints', function () {
    $s = spSetup();
    $o = spSetup();
    $mine = spSprint($s, 'active');
    $theirSprint = spSprint($o, 'active');
    $theirTask = spTask($o, $o['m1'], 'Theirs', 'not_started');
    $t = spTask($s, $s['m1'], 'T', 'not_started');
    $done = spTask($s, $s['m1'], 'Done', 'done');
    $completed = spSprint($s, 'completed');
    $inCompleted = spTask($s, $s['m1'], 'Old', 'done', ['sprint_id' => $completed->id]);

    $p = spPage($s);
    $p->call('moveTask', $theirTask->id, $mine->id, [$theirTask->id]);
    expect($theirTask->fresh()->sprint_id)->toBeNull();
    $p->call('moveTask', $t->id, $theirSprint->id, [$t->id]);
    expect($t->fresh()->sprint_id)->toBeNull();
    $p->call('moveTask', $done->id, $mine->id, [$done->id]);
    expect($done->fresh()->sprint_id)->toBeNull();
    $p->call('moveTask', $t->id, $completed->id, [$t->id]);
    expect($t->fresh()->sprint_id)->toBeNull();
    $p->call('moveTask', $inCompleted->id, 0, [$inCompleted->id]);
    expect($inCompleted->fresh()->sprint_id)->toBe($completed->id);

    // foreign ids in a reorder do not move anything of the other project
    $p->call('reorderList', [$theirTask->id, $t->id]);
    expect($theirTask->fresh()->position)->toBe(1);
});

it('refuses drags from users who cannot update the project', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $t = spTask($s, $s['m1'], 'T', 'not_started');
    $p = spPage($s);
    test()->actingAs($s['outsider']);
    $p->call('moveTask', $t->id, $a->id, [$t->id])->assertForbidden();
    $q = spPage($s);
    test()->actingAs($s['outsider']);
    $q->call('reorderList', [$t->id])->assertForbidden();
    expect($t->fresh()->sprint_id)->toBeNull();
});

// ── activity log ──────────────────────────────────────────────────────────────

it('logs sprint changes with readable sentences', function () {
    $s = spSetup();
    $s2 = spSprint($s, 'active', ['name' => 'Sprint 2']);
    $s3 = spSprint($s, 'planned', ['name' => 'Sprint 3']);
    $t = spTask($s, $s['m1'], 'T', 'not_started');

    auth()->login($s['admin']);
    $t->update(['sprint_id' => $s2->id]);
    $t->update(['sprint_id' => $s3->id]);
    $t->update(['sprint_id' => null]);
    $sentences = $t->activities()->where('field', 'sprint_id')->reorder('id')->get()->map->summary()->all();
    expect($sentences)->toBe(['added this to Sprint 2', 'moved this from Sprint 2 to Sprint 3', 'removed this from Sprint 3']);
});

it('logs rollover moves per task', function () {
    $s = spSetup();
    $a = spSprint($s, 'active', ['name' => 'Sprint 1']);
    $t = spTask($s, $s['m1'], 'T', 'active', ['sprint_id' => $a->id]);
    auth()->login($s['admin']);
    $a->complete('backlog');
    expect(Activity::where('field', 'sprint_id')->first()->summary())->toBe('removed this from Sprint 1');
});

// ── board ─────────────────────────────────────────────────────────────────────

it('sprint tab has no board view, and the active sprint header links to the Board tab with the Active sprint switch', function () {
    $s = spSetup();
    $a = spSprint($s, 'active', ['name' => 'Sprint Nine', 'goal' => 'Win the week']);
    spTask($s, $s['m1'], 'In the sprint', 'active', ['sprint_id' => $a->id]);

    $p = spPage($s)->assertSee('Sprint Nine')->assertSee('Win the week')->assertSee('Open sprint board')
        ->assertDontSeeHtml('data-test="view-board"')->assertDontSeeHtml('data-test="board-view"')->assertDontSeeHtml('aria-label="Sprint view"');
    $p->assertSeeHtml(e(route('projects.show', [$s['project'], 'tab' => 'board', 'scope' => 'sprint'])));
    expect(property_exists(Panel::class, 'mode'))->toBeFalse();
});

it('the Board tab opened from that link has the Active sprint switch on', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    spTask($s, $s['m1'], 'Sprinted', 'active', ['sprint_id' => $a->id]);
    spTask($s, $s['m1'], 'Loose', 'active');
    $this->actingAs($s['admin'])->get(route('projects.show', [$s['project'], 'tab' => 'board', 'scope' => 'sprint']))
        ->assertOk()->assertSee('Sprinted')->assertDontSee('Loose');
});

it('board component can be scoped to a sprint and switched between Active sprint and All', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    spTask($s, $s['m1'], 'Sprinted', 'active', ['sprint_id' => $a->id]);
    spTask($s, $s['m1'], 'Loose', 'active');

    $all = Livewire::actingAs($s['admin'])->withQueryParams(['scope' => 'all'])->test(Board::class, ['project' => $s['project']]);
    $all->assertSee('Sprinted')->assertSee('Loose');
    $all->set('scope', 'sprint')->assertSee('Sprinted')->assertDontSee('Loose');
    $all->set('scope', 'all')->assertSee('Loose');


    expect(Livewire::actingAs($s['admin'])->withQueryParams(['scope' => 'sprint'])->test(Board::class, ['project' => $s['project']])->get('scope'))->toBe('sprint');
});

it('Active sprint scope shows nothing and a hint when no sprint is active', function () {
    $s = spSetup();
    spTask($s, $s['m1'], 'Loose', 'active');
    Livewire::actingAs($s['admin'])->test(Board::class, ['project' => $s['project']])->set('scope', 'sprint')
        ->assertDontSee('Loose')->assertSee('No active sprint');
});

it('board drag still moves tasks while scoped to a sprint', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $t = spTask($s, $s['m1'], 'T', 'not_started', ['sprint_id' => $a->id]);
    $done = $s['project']->columns()->where('stage', 'done')->first();
    Livewire::actingAs($s['admin'])->test(Board::class, ['project' => $s['project'], 'scope' => 'sprint'])->call('taskMoved', $t->id, $done->id, 1);
    expect($t->fresh()->board_column_id)->toBe($done->id);
});

// ── tab, module list, task page, portal ──────────────────────────────────────

it('adds a Sprint tab after Modules and falls back for unknown tabs', function () {
    $s = spSetup();
    $c = Livewire::actingAs($s['admin'])->test(Show::class, ['project' => $s['project']]);
    expect(array_keys($c->viewData('tabs')))->toBe(['details', 'modules', 'sprint', 'resources', 'board', 'economics']);
    $c->set('tab', 'sprint')->assertSeeLivewire(Panel::class);
    $c->set('tab', 'bogus')->assertSet('tab', 'bogus')->assertViewHas('active', 'details');
});

it('task page Sprint section sets and clears the sprint, logged, and refuses foreign or completed sprints', function () {
    $s = spSetup();
    $a = spSprint($s, 'active', ['name' => 'Sprint A']);
    $c = spSprint($s, 'completed');
    $o = spSprint(spSetup(), 'planned');
    $t = spTask($s, $s['m1'], 'T', 'not_started');

    $p = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->assertSeeHtml('data-test="sprint-select"');
    $p->call('setSprint', $a->id);
    expect($t->fresh()->sprint_id)->toBe($a->id)
        ->and($t->activities()->where('field', 'sprint_id')->first()->summary())->toBe('added this to Sprint A');
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->call('setSprint', $c->id)->assertStatus(422);
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->call('setSprint', $o->id)->assertStatus(422);
    $p->call('setSprint', 0);
    expect($t->fresh()->sprint_id)->toBeNull();
});

it('task page does not let done tasks join a sprint', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $t = spTask($s, $s['m1'], 'T', 'done');
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->assertDontSeeHtml('data-test="sprint-select"')->call('setSprint', $a->id)->assertStatus(422);
});

it('task page sprint change is forbidden for outsiders', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    $t = spTask($s, $s['m1'], 'T', 'not_started');
    $p = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t]);
    test()->actingAs($s['outsider']);
    $p->call('setSprint', $a->id)->assertForbidden();
});

it('portal output does not expose sprints', function () {
    $s = spSetup();
    $a = spSprint($s, 'active', ['name' => 'SecretSprintName', 'goal' => 'SecretGoal']);
    spTask($s, $s['m1'], 'Visible task', 'active', ['sprint_id' => $a->id, 'client_visible' => true]);
    $clientUser = User::factory()->create(['client_id' => $s['client']->id]);
    $clientUser->assignRole('client');

    $c = Livewire::actingAs($clientUser)->test(ProjectView::class, ['project' => $s['project']])->assertSee('Visible task')
        ->assertDontSee('SecretSprintName')->assertDontSee('SecretGoal');
    expect($c->tasks->first()->getAttributes())->not->toHaveKey('sprint_id');
});

it('plan view runs a constant number of queries for about 100 tasks', function () {
    $s = spSetup();
    $a = spSprint($s, 'active');
    spPage($s); // warm
    $count = function () use ($s) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        spPage($s)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    foreach (range(1, 5) as $i) {
        spTask($s, $i % 2 ? $s['m1'] : $s['m2'], "Seed $i", 'active', ['sprint_id' => $i % 3 ? $a->id : null]);
    }
    $small = $count();
    foreach (range(6, 100) as $i) {
        $t = spTask($s, $i % 2 ? $s['m1'] : $s['m2'], "Task $i", ['not_started', 'active', 'done'][$i % 3], ['sprint_id' => $i % 3 ? $a->id : null]);
        $t->assignees()->attach($s['dev']->id);
    }
    expect($count())->toBeLessThanOrEqual($small + 2);
});

<?php

use App\Enums\AccessScope;
use App\Livewire\Checklist\Panel;
use App\Livewire\Modules\Show as ModuleShow;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Subtasks\Show as SubtaskShow;
use App\Livewire\Tasks\Board;
use App\Livewire\Tasks\Show as TaskShow;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\ProgressService;
use App\Support\Look;
use App\Support\Markdown;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
});

function polSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $module = Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'position' => 1]);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $dev->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);

    return compact('client', 'project', 'module', 'admin', 'dev');
}

function polCol(Project $p, string $name): int
{
    return $p->columns()->where('name', $name)->value('id');
}

function polTask(array $s, array $attrs = [], ?Module $module = null): Task
{
    return Task::create($attrs + ['module_id' => ($module ?? $s['module'])->id, 'creator_id' => $s['admin']->id, 'title' => 'T', 'status' => 'published', 'estimate_hours' => 1]);
}

function polItems(Task|Subtask $o, int $done, int $open = 0): void
{
    foreach (range(1, $done + $open) as $i) {
        ChecklistItem::create(['checklistable_type' => $o::class, 'checklistable_id' => $o->id, 'title' => "i$i", 'is_done' => $i <= $done, 'position' => $i]);
    }
}

// ── icon component ───────────────────────────────────────────────────────────

it('renders a Lucide icon with class passthrough and currentColor strokes', function () {
    $html = Blade::render('<x-icon name="pencil" class="h-9 w-9 text-red-500" />');
    expect($html)->toContain('<svg')->toContain('stroke="currentColor"')->toContain('h-9 w-9 text-red-500')->toContain('aria-hidden="true"');
});

it('renders an empty box for a missing or unsafe icon name instead of failing', function () {
    expect(Blade::render('<x-icon name="no-such-icon" class="h-4 w-4" />'))->toContain('data-missing-icon="no-such-icon"')->toContain('<svg');
    expect(Blade::render('<x-icon name="../../.env" />'))->toContain('data-missing-icon');
});

it('ships a licence notice and a valid svg file for every icon', function () {
    expect(file_exists(resource_path('svg/LICENSE-icons.txt')))->toBeTrue();
    foreach (glob(resource_path('svg/icons/*.svg')) as $f) {
        $x = simplexml_load_file($f);
        expect($x)->not->toBeFalse()->and((string) $x['stroke'])->toBe('currentColor');
    }
});

// ── progress rule ────────────────────────────────────────────────────────────

it('task progress: 2 of 2 checklist items ticked outside Done is 2/3', function () {
    $s = polSetup();
    $t = polTask($s);
    polItems($t, 2);
    expect(round(app(ProgressService::class)->forTask($t), 2))->toBe(66.67);
});

it('task progress: no items and not done is 0, in a done column is 100 regardless', function () {
    $s = polSetup();
    $t = polTask($s);
    $svc = app(ProgressService::class);
    expect($svc->forTask($t))->toBe(0.0);

    polItems($t, 0, 3);
    expect($svc->forTask($t->fresh()))->toBe(0.0);

    $t->update(['board_column_id' => polCol($s['project'], 'Done')]);
    expect($svc->forTask($t->fresh()))->toBe(100.0);
});

it('task progress: counts subtasks as one step each and ignores their own checklist items', function () {
    $s = polSetup();
    $t = polTask($s);
    polItems($t, 1, 1);                                   // 1 of 2 items
    $a = Subtask::create(['task_id' => $t->id, 'title' => 'a', 'is_done' => true]);
    Subtask::create(['task_id' => $t->id, 'title' => 'b']);
    polItems($a, 0, 5);                                   // not pooled in
    // steps = 2 items + 2 subtasks + 1 Done = 5, done = 1 + 1 = 2
    expect(round(app(ProgressService::class)->forTask($t->fresh()), 2))->toBe(40.0);
});

it('module progress is the hours-weighted average of the same task progress', function () {
    $s = polSetup();
    $done = polTask($s, ['estimate_hours' => 30, 'board_column_id' => polCol($s['project'], 'Done')]);        // 100%
    $part = polTask($s, ['estimate_hours' => 10]);
    polItems($part, 2);                                                                                       // 66.67%
    $svc = app(ProgressService::class);
    expect(round($svc->forModule($s['module']), 2))->toBe(round((30 * 100 + 10 * 200 / 3) / 40, 2));
    // module and task bars come from one implementation: the Module model no longer has a competing calculation
    expect(method_exists(Module::class, 'progress'))->toBeFalse();
});

it('module progress falls back to a plain average when no task has hours, and is 0 when empty', function () {
    $s = polSetup();
    $svc = app(ProgressService::class);
    expect($svc->forModule($s['module']))->toBe(0.0);
    polTask($s, ['estimate_hours' => 0, 'board_column_id' => polCol($s['project'], 'Done')]);
    polTask($s, ['estimate_hours' => 0]);
    expect($svc->forModule($s['module']))->toBe(50.0);
});

it('task, module and subtask bars on the pages show the same numbers', function () {
    $s = polSetup();
    $t = polTask($s);
    polItems($t, 2);
    Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])
        ->assertSeeHtml('data-pct="67"');
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->assertSeeHtml('data-pct="67"');
    $this->actingAs($s['admin'])->get(route('projects.show', ['project' => $s['project'], 'tab' => 'modules']))->assertOk()->assertSee('data-pct="67"', false);
});

it('turns a bar green at 100% and blue below', function () {
    expect(Blade::render('<x-ui.progress :pct="100" />'))->toContain('bg-emerald-500')->not->toContain('bg-blue-500');
    expect(Blade::render('<x-ui.progress :pct="66.7" />'))->toContain('bg-blue-500')->toContain('67%');
});

// ── colours ──────────────────────────────────────────────────────────────────

it('colours deadlines: yellow 0-3 days, plain further out, red when past, none when done', function (int $offset, bool $done, string $state) {
    Carbon::setTestNow('2030-06-10 15:30:00');
    expect(Look::deadline(Carbon::parse('2030-06-10')->addDays($offset), $done)['state'])->toBe($state);
    Carbon::setTestNow();
})->with([
    'today' => [0, false, 'soon'],
    'tomorrow' => [1, false, 'soon'],
    '2 days' => [2, false, 'soon'],
    '3 days' => [3, false, 'soon'],
    '4 days' => [4, false, 'ok'],
    'yesterday' => [-1, false, 'late'],
    'long past' => [-30, false, 'late'],
    'past but done' => [-5, true, 'none'],
    'soon but done' => [1, true, 'none'],
]);

it('renders the deadline component with the right colour classes', function () {
    Carbon::setTestNow('2030-06-10 09:00:00');
    expect(Blade::render('<x-ui.deadline :date="\Illuminate\Support\Carbon::parse(\'2030-06-11\')" />'))->toContain('text-yellow-600')->toContain('data-state="soon"')->toContain('Due tomorrow');
    expect(Blade::render('<x-ui.deadline :date="\Illuminate\Support\Carbon::parse(\'2030-06-01\')" />'))->toContain('text-rose-600');
    expect(Blade::render('<x-ui.deadline :date="\Illuminate\Support\Carbon::parse(\'2030-06-01\')" :done="true" />'))->not->toContain('text-rose-600');
    Carbon::setTestNow();
});

it('maps stages and priorities to the plan colours', function () {
    expect(Look::STAGE_COLORS)->toBe(['not_started' => 'slate', 'active' => 'brand', 'review' => 'amber', 'done' => 'green']);
    expect(Look::priority(0))->toBe(['Low', 'slate'])->and(Look::priority(1))->toBe(['Medium', 'amber'])->and(Look::priority(2))->toBe(['High', 'rose']);
    $s = polSetup();
    $col = $s['project']->columns()->where('name', 'Done')->first();
    expect(Blade::render('<x-ui.stage-badge :column="$c" />', ['c' => $col]))->toContain('badge-green')->toContain('data-stage="done"');
});

// ── task dates ───────────────────────────────────────────────────────────────

it('sets started_at on first entry into an active column and completed_at on done, clearing it when leaving', function () {
    Carbon::setTestNow('2030-03-01 10:00:00');
    $s = polSetup();
    $t = polTask($s);
    expect($t->started_at)->toBeNull()->and($t->completed_at)->toBeNull();

    $t->moveTo(polCol($s['project'], 'In progress'), 1);
    expect($t->fresh()->started_at->toDateString())->toBe('2030-03-01')->and($t->fresh()->completed_at)->toBeNull();

    Carbon::setTestNow('2030-03-04 10:00:00');
    $t->fresh()->moveTo(polCol($s['project'], 'Done'), 1);
    $f = $t->fresh();
    expect($f->started_at->toDateString())->toBe('2030-03-01')->and($f->completed_at->toDateString())->toBe('2030-03-04')->and($f->daysTaken())->toBe(3);

    Carbon::setTestNow('2030-03-09 10:00:00');
    $f->moveTo(polCol($s['project'], 'Testing'), 1);
    expect($t->fresh()->completed_at)->toBeNull()->and($t->fresh()->started_at->toDateString())->toBe('2030-03-01');

    $t->fresh()->moveTo(polCol($s['project'], 'In progress'), 1);
    expect($t->fresh()->started_at->toDateString())->toBe('2030-03-01'); // not reset
    Carbon::setTestNow();
});

it('does not set dates for not-started columns, and a task created straight in Done gets both', function () {
    $s = polSetup();
    $t = polTask($s);
    $t->moveTo(polCol($s['project'], 'Sprint plan'), 1);
    expect($t->fresh()->started_at)->toBeNull();

    $d = polTask($s, ['board_column_id' => polCol($s['project'], 'Delivered')]);
    expect($d->started_at)->not->toBeNull()->and($d->completed_at)->not->toBeNull();
});

it('sets the dates when the column changes from the task page select, and logs them', function () {
    $s = polSetup();
    $t = polTask($s);
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->call('moveColumn', polCol($s['project'], 'Done'));
    $f = $t->fresh();
    expect($f->completed_at)->not->toBeNull()
        ->and($f->activities()->where('field', 'completed_at')->exists())->toBeTrue()
        ->and($f->activities()->where('field', 'started_at')->exists())->toBeTrue();
});

it('lets the dates be edited by hand in the task sidebar, logged and validated', function () {
    $s = polSetup();
    $t = polTask($s);
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])
        ->call('openPanel', 'dates')->set('startedAt', '2030-01-01')->set('completedAt', '2030-01-05')->call('savePanel')->assertHasNoErrors()->assertSet('panel', null)
        ->assertSee('Took 4 days')
        ->call('openPanel', 'dates')->set('startedAt', '2030-02-01')->set('completedAt', '2030-01-05')->call('savePanel')->assertHasErrors('completedAt');
    $f = $t->fresh();
    expect($f->started_at->toDateString())->toBe('2030-01-01')
        ->and($f->activities()->where('field', 'started_at')->where('new', '2030-01-01')->exists())->toBeTrue();
});

it('shows derived start and end on the module page', function () {
    $s = polSetup();
    $a = polTask($s, ['started_at' => '2030-01-02', 'board_column_id' => polCol($s['project'], 'Done'), 'completed_at' => '2030-01-09']);
    $b = polTask($s, ['started_at' => '2030-01-05', 'board_column_id' => polCol($s['project'], 'Done'), 'completed_at' => '2030-01-20']);
    expect($s['module']->startedAt()->toDateString())->toBe('2030-01-02')->and($s['module']->endedAt()->toDateString())->toBe('2030-01-20');
    Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])
        ->assertSee('Jan 2, 2030')->assertSee('Jan 20, 2030');
    $b->update(['board_column_id' => polCol($s['project'], 'Testing'), 'completed_at' => null]);
    expect($s['module']->fresh()->endedAt())->toBeNull(); // not completed any more
});

// ── markdown task checkboxes ─────────────────────────────────────────────────

it('toggles the nth task item in markdown, skipping code fences and refusing stale clicks', function () {
    $md = "intro\n- [ ] one\n```\n- [ ] in code\n```\n- [x] two\n  - [ ] nested\n> - [ ] quoted\n";
    expect(Markdown::toggleTask($md, 0, true))->toContain("- [x] one")
        ->and(Markdown::toggleTask($md, 1, false))->toContain("- [ ] two")
        ->and(Markdown::toggleTask($md, 2, true))->toContain("  - [x] nested")
        ->and(Markdown::toggleTask($md, 3, true))->toContain("> - [x] quoted")
        ->and(Markdown::toggleTask($md, 0, false))->toBeNull()   // already unchecked: stale
        ->and(Markdown::toggleTask($md, 9, true))->toBeNull();
    // untouched lines stay byte-identical
    expect(Markdown::toggleTask($md, 0, true))->toBe(str_replace('- [ ] one', '- [x] one', $md));
});

it('renders clickable numbered checkboxes only when interactive', function () {
    expect(Markdown::render("- [ ] a\n- [x] b\n", true))->toContain('data-md-task="0"')->toContain('data-md-task="1"')->not->toContain('disabled');
    expect(Markdown::render("- [ ] a\n"))->toContain('disabled')->not->toContain('data-md-task');
});

it('toggling a description checkbox updates the stored markdown and logs an edit', function () {
    $s = polSetup();
    $t = polTask($s, ['description' => "Plan\n- [ ] a\n- [ ] b"]);
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])
        ->assertSeeHtml('data-md-task="1"')
        ->call('toggleTask', 'description', 1, true);
    expect($t->fresh()->description)->toBe("Plan\n- [ ] a\n- [x] b")
        ->and($t->activities()->where('field', 'description')->exists())->toBeTrue();

    // a stale click (already checked) changes nothing
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t->fresh()])->call('toggleTask', 'description', 1, true);
    expect($t->fresh()->description)->toBe("Plan\n- [ ] a\n- [x] b");
});

it('only editors may toggle a description checkbox; comment checkboxes belong to the author', function () {
    $s = polSetup();
    $t = polTask($s, ['description' => '- [ ] a']);
    $outsider = User::factory()->create();
    $outsider->assignRole('developer');
    Livewire::actingAs($outsider)->test(TaskShow::class, ['task' => $t])->assertNotFound();

    $c = $t->addComment($s['admin'], "- [ ] mine");
    Livewire::actingAs($s['dev'])->test(TaskShow::class, ['task' => $t])->call('toggleTask', 'comment:'.$c->id, 0, true)->assertForbidden();
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->call('toggleTask', 'comment:'.$c->id, 0, true);
    expect($c->fresh()->body)->toBe('- [x] mine')->and($c->fresh()->edited_by)->toBe($s['admin']->id);
});

// ── "..." menus, last edited, comment edit ───────────────────────────────────

it('shows the "..." menu on the description and comment cards with copy, quote and edit', function () {
    $s = polSetup();
    $t = polTask($s, ['description' => 'Body']);
    $t->addComment($s['admin'], 'First');
    $html = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->html();
    expect(substr_count($html, 'data-test="item-menu"'))->toBe(2)
        ->and($html)->toContain('data-test="menu-copy-link"')->toContain('data-test="menu-copy-md"')->toContain('data-test="menu-quote"')->toContain('data-test="menu-edit"')
        ->and($html)->toContain('id="description"')->toContain('id="composer"')->toContain('aria-label="More actions"');
});

it('edits a comment in place and shows who last edited it', function () {
    $s = polSetup();
    $t = polTask($s);
    $c = $t->addComment($s['dev'], 'Original');
    $lw = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t]);
    $lw->call('editComment', $c->id)->assertSet('commentEditBody', 'Original')->assertSeeHtml('data-test="comment-editor"')
        ->set('commentEditBody', 'Changed')->call('saveComment')->assertSet('editingComment', null)
        ->assertSee('Last edited by '.$s['admin']->name);
    expect($c->fresh()->body)->toBe('Changed')->and($c->fresh()->edited_by)->toBe($s['admin']->id);

    // a different, non-admin member cannot edit someone else's comment
    Livewire::actingAs($s['dev'])->test(TaskShow::class, ['task' => $t])->call('editComment', $t->addComment($s['admin'], 'x')->id)->assertForbidden();
});

it('shows "Last edited by" on a description after an edit', function () {
    $s = polSetup();
    $t = polTask($s, ['description' => 'Old']);
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->assertDontSee('Last edited by')
        ->call('editDescription')->set('description', 'New')->call('saveDescription')
        ->assertSee('Last edited by '.$s['admin']->name);
});

it('has a New task button on the task page that creates in the same module, plus a copy-link icon', function () {
    $s = polSetup();
    $t = polTask($s);
    $html = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->html();
    expect($html)->toContain('data-test="new-task"')->toContain(route('tasks.create', ['module' => $s['module']->id]))
        ->and($html)->toContain('data-test="copy-link"')->toContain('aria-label="Copy link to this task"');
});

// ── subtasks: add link, checklist, order ─────────────────────────────────────

it('keeps the add-subtask input behind a link, collapsed by default', function () {
    $s = polSetup();
    $t = polTask($s);
    $html = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->html();
    expect($html)->toContain('data-test="add-subtask-link"')->toContain('Add subtask')
        ->and($html)->toMatch('/<form[^>]*x-show="adding"[^>]*style="display:none"/');
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->set('newSubtask', 'Wire it')->call('addSubtask')->assertDispatched('subtask-added');
    expect($t->subtasks()->count())->toBe(1);
});

it('puts a checklist on the task and subtask pages', function () {
    $s = polSetup();
    $t = polTask($s);
    $sub = Subtask::create(['task_id' => $t->id, 'title' => 'Sub']);
    $this->actingAs($s['admin'])->get(route('tasks.show', $t))->assertOk()->assertSee('data-test="checklist"', false);
    $this->actingAs($s['admin'])->get(route('subtasks.show', $sub))->assertOk()->assertSee('data-test="checklist"', false);
});

it('checklist panel: add, tick, rename, delete, count and reorder, with authorisation', function () {
    $s = polSetup();
    $t = polTask($s);
    $lw = Livewire::actingAs($s['admin'])->test(Panel::class, ['checklistable' => $t]);
    $lw->set('newTitle', 'A')->call('addItem')->set('newTitle', 'B')->call('addItem')->set('newTitle', 'C')->call('addItem')->assertSee('0/3');
    [$a, $b, $c] = $t->checklistItems()->pluck('id')->all();
    $lw->call('toggleItem', $a)->assertSee('1/3')->assertDispatched('checklist-changed');
    $lw->call('startRename', $b)->set('editTitle', 'B2')->call('saveRename');
    expect(ChecklistItem::find($b)->title)->toBe('B2');
    $lw->call('reorderItems', [$c, $a, $b]);
    expect($t->checklistItems()->pluck('id')->all())->toBe([$c, $a, $b]);
    $lw->call('deleteItem', $a)->assertSee('0/2');
    expect(ChecklistItem::find($a))->toBeNull();

    $outsider = User::factory()->create();
    $outsider->assignRole('developer');
    Livewire::actingAs($outsider)->test(Panel::class, ['checklistable' => $t])->assertForbidden();
});

it('reorders subtasks and rejects ids of other tasks', function () {
    $s = polSetup();
    $t = polTask($s);
    $other = polTask($s);
    [$a, $b, $c] = collect(['a', 'b', 'c'])->map(fn ($n) => Subtask::create(['task_id' => $t->id, 'title' => $n])->id)->all();
    $foreign = Subtask::create(['task_id' => $other->id, 'title' => 'f']);
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->call('reorderSubtasks', [$c, $foreign->id, $a, $b]);
    expect($t->subtasks()->pluck('id')->all())->toBe([$c, $a, $b])->and($foreign->fresh()->position)->toBe(1);

    $outsider = User::factory()->create();
    $outsider->assignRole('developer');
    Livewire::actingAs($outsider)->test(TaskShow::class, ['task' => $t])->assertNotFound();
});

// ── one task order per module, board consistency ─────────────────────────────

it('gives new tasks the next position in their module', function () {
    $s = polSetup();
    $a = polTask($s);
    $b = polTask($s);
    $other = polTask($s, [], Module::create(['project_id' => $s['project']->id, 'name' => 'Other', 'position' => 2]));
    expect([$a->position, $b->position, $other->position])->toBe([1, 2, 1]);
});

it('reorders module tasks from the module page and the modules tab', function () {
    $s = polSetup();
    [$a, $b, $c] = collect(range(1, 3))->map(fn ($i) => polTask($s, ['title' => "t$i"])->id)->all();
    Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])->call('reorderTasks', [$c, $a, $b]);
    expect($s['module']->tasks()->orderBy('position')->pluck('id')->all())->toBe([$c, $a, $b]);

    Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']])->set('tab', 'modules')->call('reorderTasks', $s['module']->id, [$b, $c, $a]);
    expect($s['module']->tasks()->orderBy('position')->pluck('id')->all())->toBe([$b, $c, $a]);

    // read-only members cannot reorder
    $viewer = User::factory()->create();
    $viewer->assignRole('developer');
    Livewire::actingAs($viewer)->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])->assertForbidden();
});

it('a reorder of visible tasks keeps hidden tasks in their slots', function () {
    $s = polSetup();
    $a = polTask($s, ['title' => 'a']);
    $hidden = polTask($s, ['title' => 'h', 'status' => 'draft', 'creator_id' => $s['dev']->id]);
    $b = polTask($s, ['title' => 'b']);
    Task::reorder($s['module']->id, [$b->id, $a->id]);
    expect($s['module']->tasks()->orderBy('position')->pluck('id')->all())->toBe([$b->id, $hidden->id, $a->id]);
});

it('board shows tasks within a column in the module order', function () {
    $s = polSetup();
    $backlog = polCol($s['project'], 'Backlog');
    [$a, $b, $c] = collect(['a', 'b', 'c'])->map(fn ($n) => polTask($s, ['title' => $n]))->all();
    Task::reorder($s['module']->id, [$c->id, $a->id, $b->id]);
    $cols = Livewire::actingAs($s['admin'])->test(Board::class, ['project' => $s['project']])->instance()->columns();
    expect($cols->get($backlog)->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('a board drag keeps the module order consistent', function () {
    $s = polSetup();
    $backlog = polCol($s['project'], 'Backlog');
    $prog = polCol($s['project'], 'In progress');
    [$a, $b, $c, $d] = collect(['a', 'b', 'c', 'd'])->map(fn ($n) => polTask($s, ['title' => $n]))->all();
    $order = fn () => $s['module']->tasks()->orderBy('position')->pluck('id')->all();

    // c and d go to In progress, in that order
    $moveTo = fn (Task $t, int $col, int $pos, array $ids = []) => Livewire::actingAs($s['admin'])->test(Board::class, ['project' => $s['project']])->call('taskMoved', $t->id, $col, $pos, $ids);
    $moveTo($c, $prog, 1);
    $moveTo($d, $prog, 2);
    expect($order())->toBe([$a->id, $b->id, $c->id, $d->id]);

    // drag a into In progress between c and d (slot 2)
    $moveTo($a, $prog, 2);
    expect($order())->toBe([$b->id, $c->id, $a->id, $d->id]);
    expect($a->fresh()->board_column_id)->toBe($prog);

    // drag d to the top of In progress (slot 1): the module order follows
    $moveTo($d, $prog, 1);
    expect($order())->toBe([$b->id, $d->id, $c->id, $a->id]);

    // reordering inside the same column changes the same single order
    $moveTo($d, $prog, 3);
    expect($order())->toBe([$b->id, $c->id, $a->id, $d->id]);

    // the board shows exactly that order per column
    $cols = Livewire::actingAs($s['admin'])->test(Board::class, ['project' => $s['project']])->instance()->columns();
    expect($cols->get($prog)->pluck('id')->all())->toBe([$c->id, $a->id, $d->id])->and($cols->get($backlog)->pluck('id')->all())->toBe([$b->id]);
});

it('derives the slot from the dropped column order across mixed modules', function () {
    $s = polSetup();
    $m2 = Module::create(['project_id' => $s['project']->id, 'name' => 'Other', 'position' => 2]);
    $prog = polCol($s['project'], 'In progress');
    $x = polTask($s, ['title' => 'x', 'board_column_id' => $prog]);
    $y = polTask($s, ['title' => 'y', 'board_column_id' => $prog]);
    $o = polTask($s, ['title' => 'o', 'board_column_id' => $prog], $m2);
    $n = polTask($s, ['title' => 'n']);
    // n dropped at the very end of a column showing [x, o, y, n]: among its own module's cards it follows y
    Livewire::actingAs($s['admin'])->test(Board::class, ['project' => $s['project']])->call('taskMoved', $n->id, $prog, 4, [$x->id, $o->id, $y->id, $n->id]);
    expect($s['module']->tasks()->orderBy('position')->pluck('id')->all())->toBe([$x->id, $y->id, $n->id])
        ->and($m2->tasks()->pluck('position')->all())->toBe([1]);
});

it('only the module-page and modules-tab lists carry drag handles', function () {
    $s = polSetup();
    polTask($s);
    $html = Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])->html();
    expect($html)->toContain('x-sortable')->toContain('data-sort-method="reorderTasks"')->toContain('class="drag-handle')->toContain('aria-label="Drag to reorder"');
    $tab = Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']])->set('tab', 'modules')->html();
    expect($tab)->toContain('data-sort-scope="'.$s['module']->id.'"')->toContain('aria-label="Edit task"');
});

it('icon-only buttons on the pages carry a title and aria-label', function () {
    $s = polSetup();
    $t = polTask($s, ['description' => 'x']);
    $html = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $t])->html();
    expect($html)->toContain('title="Edit title" aria-label="Edit title"')->toContain('title="Edit description" aria-label="Edit description"');
    $sub = Subtask::create(['task_id' => $t->id, 'title' => 's']);
    $h2 = Livewire::actingAs($s['admin'])->test(SubtaskShow::class, ['subtask' => $sub])->html();
    expect($h2)->toContain('data-test="convert"')->toContain('data-test="delete"')->toContain('<svg');
});

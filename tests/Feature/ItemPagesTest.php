<?php

use App\Enums\AccessScope;
use App\Livewire\Modules\Create as ModuleCreate;
use App\Livewire\Modules\Show as ModuleShow;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Subtasks\Show as SubtaskShow;
use App\Livewire\Tasks\Form as TaskForm;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
    Storage::fake('local');
});

function ipSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'hourly']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $module = Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'description' => 'Module **docs**', 'position' => 1]);
    $mk = function (string $role) {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    };
    $admin = $mk('super_admin');
    $member = $mk('developer');
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    $outsider = $mk('developer');
    $client1 = User::factory()->create(['client_id' => $client->id]);
    $client1->assignRole('client');
    $task = Task::create(['module_id' => $module->id, 'creator_id' => $admin->id, 'title' => 'Build form', 'description' => 'Task text', 'status' => 'published', 'estimate_hours' => 4, 'priority' => 2, 'deadline' => '2030-01-01']);
    $sub = Subtask::create(['task_id' => $task->id, 'title' => 'Wire API', 'description' => 'Sub text', 'estimate_hours' => 2]);

    return compact('client', 'project', 'module', 'admin', 'member', 'outsider', 'client1', 'task', 'sub');
}

// ── Modules tab ──────────────────────────────────────────────────────────────

it('replaces the inline add-module form with a link to the create page', function () {
    $s = ipSetup();
    Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']])->set('tab', 'modules')
        ->assertSee('data-test="add-module"', false)->assertSee('Add module')->assertSee(route('modules.create', $s['project']), false)
        ->assertDontSee('New module name')->assertDontSee('+ Module');
});

it('renders the task progress as a small bar with the percentage beside it, not full width', function () {
    $s = ipSetup();
    $html = Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']])->set('tab', 'modules')->html();
    expect($html)->toContain('data-test="task-progress-bar"')
        ->and($html)->toContain('w-40')->toContain('data-test="progress-label"')
        ->and($html)->not->toContain('mt-2 h-1 w-full');
});

// ── add module page ──────────────────────────────────────────────────────────

it('serves the add-module page to project members and admins only', function () {
    $s = ipSetup();
    $url = route('modules.create', $s['project']);
    $this->actingAs($s['admin'])->get($url)->assertOk()->assertSee('Add module')->assertSee('Cancel');
    $this->actingAs($s['member'])->get($url)->assertOk();
    $this->actingAs($s['outsider'])->get($url)->assertForbidden();
    $this->actingAs($s['client1'])->get($url)->assertForbidden();
});

it('creates a module at the end of the project and redirects to its page', function () {
    $s = ipSetup();
    $c = Livewire::actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])
        ->set('name', 'Reports')->set('description', 'Hello')->call('save')->assertHasNoErrors();
    $m = Module::where('name', 'Reports')->first();
    expect($m->position)->toBe(2)->and($m->description)->toBe('Hello')->and($m->project_id)->toBe($s['project']->id);
    $c->assertRedirect(route('modules.show', [$s['project'], $m]));
});

it('requires a name on the add-module page and needs no description', function () {
    $s = ipSetup();
    Livewire::actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])->set('name', '  ')->call('save')->assertHasErrors('name');
    Livewire::actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])->set('name', 'Bare')->call('save')->assertHasNoErrors();
    expect(Module::where('name', 'Bare')->first()->description)->toBeNull();
    Livewire::actingAs($s['outsider'])->test(ModuleCreate::class, ['project' => $s['project']])->assertForbidden();
});

it('has no editor uploads on the add-module page', function () {
    $s = ipSetup();
    Livewire::actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])
        ->set('upload', Illuminate\Http\UploadedFile::fake()->image('a.png'))->call('storeUpload')->assertForbidden();
});

// ── module page layout + inline editing ──────────────────────────────────────

it('puts the task list right below the description, then progress, then the timeline', function () {
    $s = ipSetup();
    $html = Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])->html();
    $pos = fn ($needle) => strpos($html, $needle);
    expect($pos('data-test="description-card"'))->toBeLessThan($pos('data-test="task-list"'))
        ->and($pos('data-test="task-list"'))->toBeLessThan($pos('data-test="module-progress"'))
        ->and($pos('data-test="module-progress"'))->toBeLessThan($pos('data-test="timeline"'))
        ->and($pos('data-test="timeline"'))->toBeLessThan($pos('Add a comment'));
    expect($html)->toContain('Build form')->toContain(route('tasks.show', $s['task']))->toContain('data-test="edit-title"');
});

it('edits the module title inline and logs it; blank titles are rejected', function () {
    $s = ipSetup();
    Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])
        ->call('editTitle')->assertSet('title', 'Checkout')->set('title', '')->call('saveTitle')->assertHasErrors('title')
        ->set('title', 'Payments')->call('saveTitle')->assertHasNoErrors()->assertSet('editingTitle', false)->assertSee('Payments');
    $a = $s['module']->activities()->where('field', 'name')->first();
    expect($s['module']->fresh()->name)->toBe('Payments')->and($a->old)->toBe('Checkout')->and($a->new)->toBe('Payments');
});

it('edits the description in place, with save and cancel', function () {
    $s = ipSetup();
    Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])
        ->assertSee('<strong>docs</strong>', false)->assertDontSee('data-test="save-description"', false)
        ->call('editDescription')->assertSet('description', 'Module **docs**')->assertSee('data-test="save-description"', false)
        ->call('cancelDescription')->assertDontSee('data-test="save-description"', false)
        ->call('editDescription')->set('description', 'New *text*')->call('saveDescription')->assertSee('<em>text</em>', false);
    expect($s['module']->fresh()->description)->toBe('New *text*')->and($s['module']->activities()->where('field', 'description')->count())->toBe(1);
});

it('lets non-editors view the page without the pencils, and blocks their edits', function () {
    $s = ipSetup();
    Livewire::actingAs($s['client1'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])->assertForbidden();
    Livewire::actingAs($s['outsider'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']])->assertForbidden();
});

it('shows comments as cards with Author badge, replies, and events as single lines', function () {
    $s = ipSetup();
    $this->actingAs($s['admin']);
    $s['task']->addComment($s['admin'], 'First comment');
    $s['task']->addComment($s['member'], 'Member comment');
    $s['task']->update(['deadline' => '2031-02-02']);
    $s['task']->activities()->first()->update(['reason' => 'Client asked']);
    $html = Livewire::actingAs($s['admin'])->test(App\Livewire\Tasks\Show::class, ['task' => $s['task']->fresh()])->html();
    expect($html)->toContain('data-kind="comment"')->toContain('data-test="author-badge"')
        ->and(substr_count($html, 'data-test="author-badge"'))->toBe(1) // admin created the task; the member is no author
        ->and($html)->toContain('changed the deadline from 2030-01-01 to 2031-02-02')->toContain('Client asked');
});

// ── subtask page ─────────────────────────────────────────────────────────────

it('serves the subtask page like the task page: members and admins only', function () {
    $s = ipSetup();
    $url = route('subtasks.show', $s['sub']);
    $this->actingAs($s['admin'])->get($url)->assertOk()->assertSee('Wire API')->assertSee('Breadcrumb', false)->assertSee('Sub text')->assertSee('Convert to task');
    $this->actingAs($s['member'])->get($url)->assertOk();
    $this->actingAs($s['outsider'])->get($url)->assertNotFound();
    $this->actingAs($s['client1'])->get($url)->assertNotFound();
    $this->actingAs($s['admin'])->get(route('subtasks.show', 99999))->assertNotFound();

    // a draft task of another user hides its subtasks (404 like the task page)
    $draft = Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Draft', 'status' => 'draft']);
    $dsub = Subtask::create(['task_id' => $draft->id, 'title' => 'Hidden']);
    $this->actingAs($s['member'])->get(route('subtasks.show', $dsub))->assertNotFound();
});

it('edits subtask title, description, assignees, labels, estimate, done and comments on its own page', function () {
    $s = ipSetup();
    $l = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $c = Livewire::actingAs($s['admin'])->test(SubtaskShow::class, ['subtask' => $s['sub']]);
    $c->call('editTitle')->set('title', 'Wire API v2')->call('saveTitle')->assertHasNoErrors()
        ->call('editDescription')->set('description', '**bold**')->call('saveDescription')
        ->call('openPanel', 'assignees')->set('assigneeIds', [$s['member']->id, $s['outsider']->id])->call('savePanel')
        ->call('openPanel', 'labels')->set('labelIds', [$l->id])->call('savePanel')
        ->call('openPanel', 'estimate')->set('estimate', '-1')->call('savePanel')->assertHasErrors('estimate')
        ->set('estimate', '3.5')->call('savePanel')->assertHasNoErrors()
        ->call('toggleDone')
        ->set('commentBody', 'Sub note')->call('postComment')->assertSee('Sub note');
    $root = $s['sub']->comments()->first();
    $c->call('startReply', $root->id)->set('replyBody', 'Sub reply')->call('postReply')->assertSee('Sub reply');

    $sub = $s['sub']->fresh();
    expect($sub)->title->toBe('Wire API v2')->description->toBe('**bold**')->estimate_hours->toBe(3.5)->is_done->toBeTrue();
    expect($sub->assignees()->pluck('users.id')->all())->toBe([$s['member']->id])->and($sub->labels()->count())->toBe(1);
    expect($sub->activities()->pluck('field')->filter()->unique()->sort()->values()->all())->toBe(['assignees', 'description', 'estimate_hours', 'is_done', 'labels', 'title']);
    expect($s['task']->comments()->count())->toBe(0);
});

it('stops non-assignee members editing a subtask', function () {
    $s = ipSetup();
    Livewire::actingAs($s['member'])->test(SubtaskShow::class, ['subtask' => $s['sub']])->call('editTitle')->assertForbidden();
    $s['task']->assignees()->attach($s['member']->id);
    Livewire::actingAs($s['member'])->test(SubtaskShow::class, ['subtask' => $s['sub']])->call('editTitle')->set('title', 'Mine')->call('saveTitle')->assertHasNoErrors();
});

it('deletes a subtask from its page and returns to the parent task', function () {
    $s = ipSetup();
    Livewire::actingAs($s['admin'])->test(SubtaskShow::class, ['subtask' => $s['sub']])->call('deleteSubtask')->assertRedirect(route('tasks.show', $s['task']));
    expect(Subtask::count())->toBe(0);
});

// ── convert subtask to task ──────────────────────────────────────────────────

it('converts a subtask to a task, moving comments and attachments without duplicating files', function () {
    $s = ipSetup();
    $this->actingAs($s['admin']);
    $l = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $sub = $s['sub'];
    $sub->syncAssignees([$s['member']->id]);
    $sub->syncLabels([$l->id]);
    $root = $sub->addComment($s['admin'], 'A comment');
    $sub->addComment($s['member'], 'A reply', $root->id);
    Storage::disk('local')->put('attachments/x/spec.txt', 'data');
    $att = $sub->attachments()->create(['path' => 'attachments/x/spec.txt', 'name' => 'spec.txt', 'mime' => 'text/plain', 'size' => 4]);

    $c = Livewire::actingAs($s['admin'])->test(SubtaskShow::class, ['subtask' => $sub->fresh()])->call('convertToTask');
    $new = Task::where('title', 'Wire API')->where('id', '!=', $s['task']->id)->first();
    $c->assertRedirect(route('tasks.show', $new));

    expect($new)->module_id->toBe($s['module']->id)->creator_id->toBe($s['admin']->id)->description->toBe('Sub text')->estimate_hours->toBe(2.0)
        ->and($new->scope->value)->toBe('in')->and($new->board_column_id)->toBe($s['project']->columns()->value('id'));
    expect($new->assignees()->pluck('users.id')->all())->toBe([$s['member']->id])->and($new->labels()->pluck('labels.id')->all())->toBe([$l->id]);
    expect(Subtask::find($sub->id))->toBeNull();
    // comments + replies moved, row ids untouched
    expect($new->comments()->count())->toBe(2)->and(Comment::where('commentable_type', Subtask::class)->count())->toBe(0)->and($new->comments()->whereNotNull('parent_id')->count())->toBe(1);
    // the attachment row moved; the file is still there, once
    expect($att->fresh())->attachable_id->toBe($new->id)->and($new->attachments()->count())->toBe(1)->and(Attachment::count())->toBe(1);
    Storage::disk('local')->assertExists('attachments/x/spec.txt');
    // activity on the new task
    $a = $new->activities()->where('event', 'converted')->first();
    expect($a->old)->toBe('Build form')->and($a->summary())->toBe('converted this from a subtask of Build form');
    // the moved items show on the new task page
    Livewire::actingAs($s['admin'])->test(App\Livewire\Tasks\Show::class, ['task' => $new])->assertSee('A comment')->assertSee('converted this from a subtask of Build form')->assertSee('spec.txt');
    // deleting the new task cleans up its files
    $new->delete();
    Storage::disk('local')->assertMissing('attachments/x/spec.txt');
});

it('forbids converting for users who cannot edit the parent task', function () {
    $s = ipSetup();
    Livewire::actingAs($s['member'])->test(SubtaskShow::class, ['subtask' => $s['sub']])->call('convertToTask')->assertForbidden();
    expect(Subtask::count())->toBe(1);
});

// ── copy module ──────────────────────────────────────────────────────────────

function ipRichModule(array $s): void
{
    test()->actingAs($s['admin']);
    $l = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $s['module']->updateBudget(['budget_hours' => '10', 'hourly_rate' => '50.00'], $s['admin'], '');
    $s['module']->update(['deadline' => '2030-06-06', 'client_visible' => true]);
    $s['module']->syncAssignees([$s['member']->id]);
    $s['module']->syncLabels([$l->id]);
    $s['task']->syncAssignees([$s['member']->id]);
    $s['task']->addComment($s['admin'], 'not copied');
    $s['sub']->syncAssignees([$s['member']->id]);
}

it('opens the create page prefilled as a copy of the module, nothing saved yet', function () {
    $s = ipSetup();
    ipRichModule($s);
    $before = Module::count();
    $this->actingAs($s['admin'])->get(route('modules.show', [$s['project'], $s['module']]))->assertSee(route('modules.create', ['project' => $s['project'], 'copy' => $s['module']->id]), false);
    $url = route('modules.create', ['project' => $s['project'], 'copy' => $s['module']->id]);
    $this->actingAs($s['admin'])->get($url)->assertOk()->assertSee('Copy of Checkout')->assertSee('Also copy 1 task and their subtasks');
    Livewire::withQueryParams(['copy' => $s['module']->id])->actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])
        ->assertSet('name', 'Copy of Checkout')->assertSet('description', 'Module **docs**')->assertSet('copyTasks', true);
    expect(Module::count())->toBe($before);
});

it('copies the module with its tasks and subtasks, but not comments, attachments or activity', function () {
    $s = ipSetup();
    ipRichModule($s);
    Livewire::withQueryParams(['copy' => $s['module']->id])->actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])->call('save')->assertHasNoErrors();
    $m = Module::where('name', 'Copy of Checkout')->first();
    expect($m->position)->toBe(2)->and($m->deadline->toDateString())->toBe('2030-06-06')->and($m->client_visible)->toBeTrue()
        ->and($m->budget_hours)->toBe('10.00')->and($m->hourly_rate)->toBe('50.00')->and($m->description)->toBe('Module **docs**');
    expect($m->assignees()->pluck('users.id')->all())->toBe([$s['member']->id])->and($m->labels()->count())->toBe(1);
    expect($m->comments()->count())->toBe(0)->and($m->activities()->count())->toBe(0)->and($m->budgetChanges()->count())->toBe(0);

    $t = $m->tasks()->first();
    expect($m->tasks()->count())->toBe(1)->and($t)->title->toBe('Build form')->priority->toBe(2)->estimate_hours->toBe(4.0)->creator_id->toBe($s['admin']->id)
        ->and($t->deadline->toDateString())->toBe('2030-01-01')->and($t->board_column_id)->toBe($s['project']->columns()->value('id'));
    expect($t->assignees()->count())->toBe(1)->and($t->comments()->count())->toBe(0)->and($t->activities()->count())->toBe(0);
    $st = $t->subtasks()->first();
    expect($t->subtasks()->count())->toBe(1)->and($st)->title->toBe('Wire API')->description->toBe('Sub text')->is_done->toBeFalse()->and($st->assignees()->count())->toBe(1);
    // the source is untouched
    expect($s['task']->fresh()->module_id)->toBe($s['module']->id)->and($s['task']->comments()->count())->toBe(1);
});

it('copies only the module when "also copy tasks" is switched off', function () {
    $s = ipSetup();
    Livewire::withQueryParams(['copy' => $s['module']->id])->actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])->set('copyTasks', false)->call('save');
    $m = Module::where('name', 'Copy of Checkout')->first();
    expect($m->tasks()->count())->toBe(0)->and(Task::count())->toBe(1);
});

it('rejects copying a module from another project', function () {
    $s = ipSetup();
    $other = Project::create(['client_id' => $s['client']->id, 'name' => 'Other', 'status' => 'active']);
    $this->actingAs($s['admin'])->get(route('modules.create', ['project' => $other, 'copy' => $s['module']->id]))->assertNotFound();
});

// ── task create + copy ───────────────────────────────────────────────────────

it('opens a prefilled task create page from the Copy action, saving nothing until Create', function () {
    $s = ipSetup();
    $this->actingAs($s['admin']);
    $label = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $s['task']->update(['client_visible' => true, 'price' => 10, 'scope' => 'out']);
    $s['task']->syncLabels([$label->id]);
    $s['task']->syncAssignees([$s['member']->id]);
    $s['task']->addComment($s['admin'], 'not copied');
    $count = Task::count();

    $this->get(route('tasks.show', $s['task']))->assertSee(route('tasks.create', ['copy' => $s['task']->id]), false);
    $this->get(route('tasks.create', ['copy' => $s['task']->id]))->assertOk()->assertSee('Copy of Build form')->assertSee('Also copy 1 subtask');

    Livewire::withQueryParams(['copy' => $s['task']->id])->test(TaskForm::class)
        ->assertSet('title', 'Copy of Build form')->assertSet('description', 'Task text')->assertSet('priority', 2)->assertSet('estimate_hours', 4.0)
        ->assertSet('price', 10.0)->assertSet('scope', 'out')->assertSet('client_visible', true)->assertSet('deadline', '2030-01-01')
        ->assertSet('module_id', $s['module']->id)->assertSet('labelIds', [$label->id])->assertSet('assigneeIds', [$s['member']->id])->assertSet('copySubtasks', true);
    expect(Task::count())->toBe($count);
});

it('creates the copy with its subtasks, and without them when unchecked; no comments or activity come along', function () {
    $s = ipSetup();
    $this->actingAs($s['admin']);
    $s['task']->addComment($s['admin'], 'not copied');
    Subtask::create(['task_id' => $s['task']->id, 'title' => 'Second']);

    Livewire::withQueryParams(['copy' => $s['task']->id])->test(TaskForm::class)->call('save')->assertHasNoErrors();
    $copy = Task::where('title', 'Copy of Build form')->first();
    expect($copy->subtasks()->pluck('title')->all())->toBe(['Wire API', 'Second'])->and($copy->comments()->count())->toBe(0)->and($copy->activities()->count())->toBe(0)
        ->and($copy->creator_id)->toBe($s['admin']->id)->and($copy->board_column_id)->toBe($s['project']->columns()->value('id'));

    Livewire::withQueryParams(['copy' => $s['task']->id])->test(TaskForm::class)->set('title', 'Lean copy')->set('copySubtasks', false)->call('save');
    expect(Task::where('title', 'Lean copy')->first()->subtasks()->count())->toBe(0);
});

it('lets a task copy go to another module via the module select', function () {
    $s = ipSetup();
    $other = Module::create(['project_id' => $s['project']->id, 'name' => 'Other', 'position' => 2]);
    Livewire::withQueryParams(['copy' => $s['task']->id])->actingAs($s['admin'])->test(TaskForm::class)->assertSee('Other')->set('module_id', $other->id)->call('save')->assertHasNoErrors();
    expect(Task::where('title', 'Copy of Build form')->first()->module_id)->toBe($other->id);
});

it('creates a plain new task from ?module= and keeps copy sources behind authorisation', function () {
    $s = ipSetup();
    Livewire::withQueryParams(['module' => $s['module']->id])->actingAs($s['admin'])->test(TaskForm::class)->assertSet('module_id', $s['module']->id)
        ->set('title', 'Fresh')->call('save')->assertHasNoErrors();
    expect(Task::where('title', 'Fresh')->exists())->toBeTrue();

    $this->actingAs($s['outsider'])->get(route('tasks.create', ['copy' => $s['task']->id]))->assertNotFound();
    $this->actingAs($s['member'])->get(route('tasks.create', ['copy' => $s['task']->id]))->assertOk(); // active project members may add tasks
});

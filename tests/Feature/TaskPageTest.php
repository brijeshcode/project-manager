<?php

use App\Enums\AccessScope;
use App\Livewire\Modules\Create as ModuleCreate;
use App\Livewire\Portal\ProjectView;
use App\Livewire\Projects\Form as ProjectForm;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Tasks\Form as TaskForm;
use App\Livewire\Tasks\Show;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
    Storage::fake('local');
});

function tpSetup(): array
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
    $task = Task::create(['module_id' => $module->id, 'creator_id' => $admin->id, 'title' => 'Build form', 'status' => 'published', 'client_visible' => true]);

    return compact('client', 'project', 'module', 'admin', 'member', 'outsider', 'c1', 'c2', 'task');
}

function tpPage(array $s, ?User $as = null)
{
    return Livewire::actingAs($as ?? $s['admin'])->test(Show::class, ['task' => $s['task']->fresh()]);
}

function tpPortal(array $s, User $as)
{
    return Livewire::actingAs($as)->test(ProjectView::class, ['project' => $s['project']]);
}

// ── activity logging ─────────────────────────────────────────────────────────

it('logs every tracked task field, with the column as its name', function () {
    $s = tpSetup();
    $this->actingAs($s['admin']);
    $t = $s['task'];
    $cols = $s['project']->columns;
    Activity::query()->delete();

    $t->update(['title' => 'Build checkout form', 'description' => 'New', 'deadline' => '2030-01-01', 'priority' => 2,
        'board_column_id' => $cols[2]->id, 'client_visible' => false, 'estimate_hours' => 5]);

    $rows = $t->activities()->get()->keyBy('field');
    expect($rows->keys()->sort()->values()->all())->toBe(['board_column_id', 'client_visible', 'deadline', 'description', 'estimate_hours', 'priority', 'started_at', 'title']);
    expect($rows['title'])->old->toBe('Build form')->new->toBe('Build checkout form');
    expect($rows['board_column_id'])->old->toBe($cols[0]->name)->new->toBe($cols[2]->name);
    expect($rows['priority'])->old->toBe('Low')->new->toBe('High');
    expect($rows['deadline'])->old->toBeNull()->new->toBe('2030-01-01');
    expect($rows['client_visible'])->old->toBe('yes')->new->toBe('no');
    expect($rows['board_column_id']->summary())->toContain('changed the column from');

    $t->update(['title' => 'Build checkout form']); // unchanged
    expect($t->activities()->count())->toBe(8);
    $t->update(['status' => 'draft']); // untracked
    expect($t->activities()->count())->toBe(8);
});

it('logs task assign, unassign, label add and remove', function () {
    $s = tpSetup();
    $this->actingAs($s['admin']);
    $t = $s['task'];
    $l = Label::create(['name' => 'Bug', 'color' => '#f00']);

    $t->syncAssignees([$s['member']->id]);
    $t->syncAssignees([]);
    $t->syncLabels([$l->id]);
    $t->syncLabels([]);

    expect($t->activities()->reorder('id')->pluck('event')->all())->toBe(['assigned', 'unassigned', 'label_added', 'label_removed']);
    expect($t->activities()->where('event', 'assigned')->first()->new)->toBe($s['member']->name);
});

it('logs subtask fields, assignment and labels', function () {
    $s = tpSetup();
    $this->actingAs($s['admin']);
    $sub = Subtask::create(['task_id' => $s['task']->id, 'title' => 'API']);
    $l = Label::create(['name' => 'Bug', 'color' => '#f00']);

    $sub->update(['title' => 'API v2', 'description' => 'Docs', 'estimate_hours' => 3]);
    $sub->syncAssignees([$s['member']->id]);
    $sub->syncAssignees([]);
    $sub->syncLabels([$l->id]);
    $sub->syncLabels([]);

    expect($sub->activities()->reorder('id')->pluck('event')->all())->toBe(['changed', 'changed', 'changed', 'assigned', 'unassigned', 'label_added', 'label_removed']);
    expect($sub->activities()->where('field', 'title')->first())->old->toBe('API')->new->toBe('API v2');
});

// ── task page: authorisation + editing ───────────────────────────────────────

it('serves the task page to members and admins, not outsiders, clients or others drafts', function () {
    $s = tpSetup();
    $url = route('tasks.show', $s['task']);
    $this->actingAs($s['member'])->get($url)->assertOk()->assertSee('Build form');
    $this->actingAs($s['admin'])->get($url)->assertOk();
    $this->actingAs($s['outsider'])->get($url)->assertNotFound(); // not visible to non-members
    $this->actingAs($s['c1'])->get($url)->assertNotFound();

    $draft = Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['member']->id, 'title' => 'Secret draft', 'status' => 'draft']);
    $this->actingAs($s['admin'])->get(route('tasks.show', $draft))->assertNotFound();
    $this->actingAs($s['member'])->get(route('tasks.show', $draft))->assertOk();
});

it('edits every task field from the sidebar panels and logs it', function () {
    $s = tpSetup();
    $label = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $col = $s['project']->columns[3];
    tpPage($s)->call('editTitle')->set('title', 'Renamed')->call('saveTitle')->assertHasNoErrors()->assertSet('editingTitle', false)
        ->call('editDescription')->set('description', "- [ ] a\n- b")->call('saveDescription')->assertSet('editingDesc', false)
        ->call('openPanel', 'deadline')->set('deadline', '2030-03-03')->set('deadlineReason', 'Client asked')->call('savePanel')->assertSet('panel', null)
        ->call('openPanel', 'priority')->set('priority', 2)->call('savePanel')
        ->call('moveColumn', $col->id)
        ->call('openPanel', 'visibility')->set('clientVisible', false)->call('savePanel')
        ->call('openPanel', 'estimate')->set('estimate', '6')->set('scope', 'out')->set('budget_amount', '120.50')->call('savePanel')->assertHasNoErrors()
        ->call('openPanel', 'assignees')->set('assigneeIds', [$s['member']->id, $s['outsider']->id])->call('savePanel')
        ->call('openPanel', 'labels')->set('labelIds', [$label->id])->call('savePanel');

    $t = $s['task']->fresh();
    expect($t)->title->toBe('Renamed')->priority->toBe(2)->board_column_id->toBe($col->id)->client_visible->toBeFalse();
    expect($t->deadline->toDateString())->toBe('2030-03-03');
    expect($t->budget_amount)->toBe(120.5);
    expect($t->assignees()->pluck('users.id')->all())->toBe([$s['member']->id]); // outsider is not on the project
    expect($t->activities()->where('field', 'deadline')->first())->reason->toBe('Client asked');
    expect($t->activities()->where('field', 'board_column_id')->first()->new)->toBe($col->name);
    expect($t->activities()->where('field', 'title')->first())->old->toBe('Build form')->new->toBe('Renamed');
    expect($t->activities()->where('field', 'description')->exists())->toBeTrue();

    tpPage($s)->assertSee('Mar 3, 2030')->assertSee('Renamed');
});

it('validates task money, dates and title on the page', function () {
    $s = tpSetup();
    tpPage($s)->call('openPanel', 'estimate')->set('budget_amount', '-5')->set('estimate', '-1')->call('savePanel')->assertHasErrors(['budget_amount', 'estimate'])->assertSet('panel', 'estimate');
    tpPage($s)->call('openPanel', 'deadline')->set('deadline', 'nope')->call('savePanel')->assertHasErrors('deadline');
    tpPage($s)->call('openPanel', 'estimate')->set('scope', 'out')->set('budget_amount', '99999999')->call('savePanel')->assertHasErrors('budget_amount');
    tpPage($s)->call('editTitle')->set('title', '')->call('saveTitle')->assertHasErrors('title');
});

it('changes the column from the sidebar and only inside the project', function () {
    $s = tpSetup();
    $col = $s['project']->columns[2];
    tpPage($s)->call('moveColumn', $col->id);
    expect($s['task']->fresh()->board_column_id)->toBe($col->id);

    $other = Project::create(['client_id' => $s['client']->id, 'name' => 'Other', 'status' => 'active']);
    tpPage($s)->call('moveColumn', $other->columns()->first()->id)->assertStatus(422);
});

it('forbids editing for members who are not assignees, allows assignees', function () {
    $s = tpSetup();
    tpPage($s, $s['member'])->call('editTitle')->assertForbidden();
    $s['task']->assignees()->attach($s['member']->id);
    tpPage($s, $s['member'])->call('editTitle')->set('title', 'By assignee')->call('saveTitle')->assertHasNoErrors();
    expect($s['task']->fresh()->title)->toBe('By assignee');
});

it('shows the inline description editor when empty and saves it', function () {
    $s = tpSetup();
    tpPage($s)->assertSee('data-test="empty-description"', false)->set('description', '**hi**')->call('saveDescription');
    expect($s['task']->fresh()->description)->toBe('**hi**');
    tpPage($s)->assertDontSee('empty-description')->assertSee('<strong>hi</strong>', false);
});

it('renders task markdown through the sanitiser', function () {
    $s = tpSetup();
    $s['task']->update(['description' => "<script>alert(1)</script>\n\n[x](javascript:alert(1))"]);
    tpPage($s)->assertDontSee('<script>', false)->assertDontSee('javascript:', false);
});

it('posts comments and threaded replies on the task timeline', function () {
    $s = tpSetup();
    tpPage($s)->set('commentBody', 'Hello')->call('postComment')->assertSet('commentBody', '');
    $root = $s['task']->comments()->first();
    tpPage($s, $s['member'])->call('startReply', $root->id)->set('replyBody', 'Hi back')->call('postReply');
    expect($s['task']->comments()->where('parent_id', $root->id)->first())->body->toBe('Hi back');
    tpPage($s)->set('commentBody', '')->call('postComment')->assertHasErrors('commentBody');
    tpPage($s)->assertSee('Hello')->assertSee('Hi back');
});

it('shows a deadline change in the timeline', function () {
    $s = tpSetup();
    tpPage($s)->call('openPanel', 'deadline')->set('deadline', '2031-01-02')->call('savePanel');
    tpPage($s)->assertSee('changed the deadline from none to 2031-01-02');
});

// ── subtask list ─────────────────────────────────────────────────────────────

it('adds subtasks inline, toggles done and links each to its own page', function () {
    $s = tpSetup();
    $c = tpPage($s)->set('newSubtask', 'Cart API')->call('addSubtask')->assertSet('newSubtask', '');
    $sub = $s['task']->subtasks()->first();
    expect($sub->title)->toBe('Cart API');
    $c->assertSee('0/1 done')->assertSee(route('subtasks.show', $sub), false)->assertDontSee('subtask-panel');
    $c->call('toggleSubtask', $sub->id)->assertSee('1/1 done');
    expect($sub->fresh()->is_done)->toBeTrue();
    tpPage($s)->set('newSubtask', '')->call('addSubtask')->assertHasErrors('newSubtask');
});

it('will not toggle a subtask of another task', function () {
    $s = tpSetup();
    $other = Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Other', 'status' => 'published']);
    $foreign = Subtask::create(['task_id' => $other->id, 'title' => 'Foreign']);
    expect(fn () => tpPage($s)->call('toggleSubtask', $foreign->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($foreign->fresh()->is_done)->toBeFalse();
});

it('stores task uploads from the description editor', function () {
    $s = tpSetup();
    tpPage($s)->set('upload', UploadedFile::fake()->image('a.png'))->call('storeUpload');
    expect($s['task']->attachments()->count())->toBe(1);
});

// ── attachment route ─────────────────────────────────────────────────────────

it('lets only project members download task and subtask attachments', function () {
    $s = tpSetup();
    $sub = Subtask::create(['task_id' => $s['task']->id, 'title' => 'S']);
    foreach ([$s['task'], $sub] as $owner) {
        Storage::disk('local')->put('attachments/x/'.$owner::class.'.txt', 'data');
        $a = $owner->attachments()->create(['path' => 'attachments/x/'.$owner::class.'.txt', 'name' => 'spec.txt', 'mime' => 'text/plain', 'size' => 4]);
        $this->actingAs($s['member'])->get($a->url())->assertOk();
        $this->actingAs($s['admin'])->get($a->url())->assertOk();
        $this->actingAs($s['outsider'])->get($a->url())->assertForbidden();
        $this->actingAs($s['c1'])->get($a->url())->assertForbidden();
    }
});

it('deletes task comments, activity and files with the task and its subtasks', function () {
    $s = tpSetup();
    $this->actingAs($s['admin']);
    $sub = Subtask::create(['task_id' => $s['task']->id, 'title' => 'S']);
    Storage::disk('local')->put('attachments/x/f.txt', 'd');
    $sub->attachments()->create(['path' => 'attachments/x/f.txt', 'name' => 'f.txt', 'size' => 1]);
    $sub->addComment($s['admin'], 'c');
    $s['task']->addComment($s['admin'], 'c');
    $s['task']->update(['title' => 'x']);

    $s['task']->delete();
    expect(App\Models\Comment::count())->toBe(0)->and(App\Models\Activity::count())->toBe(0)->and(App\Models\Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing('attachments/x/f.txt');
});

// ── client portal ────────────────────────────────────────────────────────────

it('shows clients only client-visible tasks and never activity, attachments or the staff page', function () {
    $s = tpSetup();
    $this->actingAs($s['admin']);
    Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Internal only', 'status' => 'published', 'client_visible' => false]);
    $s['task']->update(['title' => 'Shown to client']);
    $s['task']->attachments()->create(['path' => 'attachments/x/secret.pdf', 'name' => 'secret.pdf', 'size' => 1]);

    tpPortal($s, $s['c1'])->assertSee('Shown to client')->assertDontSee('Internal only')
        ->assertDontSee('secret.pdf')->assertDontSee('changed title');
});

it('lets clients comment and reply on tasks, own thread only, with the markdown box', function () {
    $s = tpSetup();
    $t = $s['task'];
    tpPortal($s, $s['c1'])->assertSee('data-test="threads-task-'.$t->id.'"', false)->assertSeeHtml('Your comment');
    tpPortal($s, $s['c1'])->set('commentBody', '**Q**')->call('addComment', $t->id);
    $root = $t->comments()->first();
    $t->addComment($s['admin'], 'Answer', $root->id);
    $t->addComment($s['admin'], 'Internal');
    tpPortal($s, $s['c1'])->set('replyBody.'.$root->id, 'Thanks')->call('reply', 'task', $t->id, $root->id);

    tpPortal($s, $s['c1'])->assertSee('<strong>Q</strong>', false)->assertSee('Answer')->assertSee('Thanks')->assertDontSee('Internal');
    tpPortal($s, $s['c2'])->assertDontSee('Answer');
    $internal = $t->comments()->where('body', 'Internal')->first();
    expect(fn () => tpPortal($s, $s['c2'])->set('replyBody.'.$internal->id, 'x')->call('reply', 'task', $t->id, $internal->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('keeps clients from commenting on tasks that are not client-visible', function () {
    $s = tpSetup();
    $s['task']->update(['client_visible' => false]);
    tpPortal($s, $s['c1'])->set('commentBody', 'x')->call('addComment', $s['task']->id)->assertForbidden();
});

// ── modules tab ──────────────────────────────────────────────────────────────

it('renders the Jira-style modules tab: status, deadline, progress, assignees, labels, task links', function () {
    $s = tpSetup();
    $this->actingAs($s['admin']);
    $cols = $s['project']->columns;
    $done = $cols->firstWhere('stage', 'done');
    $s['module']->update(['deadline' => now()->subDays(3)]);
    $s['module']->syncAssignees([$s['member']->id]);
    $s['module']->syncLabels([Label::create(['name' => 'Payments', 'color' => '#0a0'])->id]);
    $s['task']->update(['board_column_id' => $done->id, 'deadline' => now()->addDay()]);
    $s['task']->syncAssignees([$s['member']->id]);
    Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Second', 'status' => 'published']);

    Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']])->set('tab', 'modules')
        ->assertSee('data-test="module-row"', false)
        ->assertSeeInOrder(['Checkout', 'Working', 'text-rose-600'], false) // not completed + past deadline = red
        ->assertSee('50%')->assertSee('2 tasks')->assertSee('Payments')
        ->assertSee(route('tasks.show', $s['task']), false)
        ->assertSee($done->name)->assertSee('done');
});

it('shows a completed module without the overdue red', function () {
    $s = tpSetup();
    $s['module']->update(['deadline' => now()->subDays(3), 'status_override' => 'completed']);
    Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']])->set('tab', 'modules')
        ->assertDontSee('text-rose-600', false)->assertSee('Completed');
    expect($s['module']->fresh()->status())->toBe('completed');
});

it('links tasks from the board and module page to the task page', function () {
    $s = tpSetup();
    $this->actingAs($s['admin'])->get(route('modules.show', [$s['project'], $s['module']]))->assertSee(route('tasks.show', $s['task']), false);
    Livewire::actingAs($s['admin'])->test(App\Livewire\Tasks\Board::class, ['project' => $s['project']])->assertSee(route('tasks.show', $s['task']), false);
});

// ── editor everywhere ────────────────────────────────────────────────────────

it('uses the markdown editor on the project, task and module forms', function () {
    $s = tpSetup();
    Livewire::actingAs($s['admin'])->test(ProjectForm::class)->assertSee('Write')->assertSee('Preview')->assertSee('wire:model="description"', false)->assertSee('wire:model="aim"', false);
    Livewire::actingAs($s['admin'])->test(TaskForm::class, ['task' => $s['task']])->assertSee('Preview')->assertSee('Attach');
    Livewire::actingAs($s['admin'])->test(TaskForm::class)->assertSee('Preview')->assertDontSee('Attach');
    Livewire::actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])->assertSee('Preview')->assertDontSee('Attach');
});

it('saves a project form description as markdown and renders it on the project page', function () {
    $s = tpSetup();
    Livewire::actingAs($s['admin'])->test(ProjectForm::class, ['project' => $s['project']])
        ->set('description', "- one\n- two")->set('aim', '**Win**')->call('save')->assertHasNoErrors();
    Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']->fresh()])
        ->assertSee('<li>one</li>', false)->assertSee('<strong>Win</strong>', false);
});

it('does not allow uploads from the project form or the portal', function () {
    $s = tpSetup();
    Livewire::actingAs($s['admin'])->test(ProjectForm::class)->set('upload', UploadedFile::fake()->image('a.png'))->call('storeUpload')->assertForbidden();
    tpPortal($s, $s['c1'])->set('upload', UploadedFile::fake()->image('a.png'))->call('storeUpload')->assertForbidden();
});

it('creates a module with a description from the add-module page', function () {
    $s = tpSetup();
    Livewire::actingAs($s['admin'])->test(ModuleCreate::class, ['project' => $s['project']])
        ->set('name', 'Reports')->set('description', '**x**')->call('save')->assertHasNoErrors();
    expect(Module::where('name', 'Reports')->first()->description)->toBe('**x**');
});

it('prompts managers to add a module description inline when it is empty', function () {
    $s = tpSetup();
    Livewire::actingAs($s['admin'])->test(App\Livewire\Modules\Show::class, ['project' => $s['project'], 'module' => $s['module']])
        ->assertSee('data-test="empty-description"', false)
        ->set('description', 'Hello **there**')->call('saveDescription');
    expect($s['module']->fresh()->description)->toBe('Hello **there**');
});

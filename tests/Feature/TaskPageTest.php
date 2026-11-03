<?php

use App\Enums\AccessScope;
use App\Livewire\Modules\Manager;
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
    expect($rows->keys()->sort()->values()->all())->toBe(['board_column_id', 'client_visible', 'deadline', 'description', 'estimate_hours', 'priority', 'title']);
    expect($rows['title'])->old->toBe('Build form')->new->toBe('Build checkout form');
    expect($rows['board_column_id'])->old->toBe($cols[0]->name)->new->toBe($cols[2]->name);
    expect($rows['priority'])->old->toBe('Low')->new->toBe('High');
    expect($rows['deadline'])->old->toBeNull()->new->toBe('2030-01-01');
    expect($rows['client_visible'])->old->toBe('yes')->new->toBe('no');
    expect($rows['board_column_id']->summary())->toContain('changed column from');

    $t->update(['title' => 'Build checkout form']); // unchanged
    expect($t->activities()->count())->toBe(7);
    $t->update(['status' => 'draft']); // untracked
    expect($t->activities()->count())->toBe(7);
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

it('lets the whole task be edited from the page and logs it', function () {
    $s = tpSetup();
    $label = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $col = $s['project']->columns[3];
    tpPage($s)->call('edit')
        ->set('title', 'Renamed')->set('description', "- [ ] a\n- b")->set('deadline', '2030-03-03')->set('deadlineReason', 'Client asked')
        ->set('priority', 2)->set('columnId', $col->id)->set('clientVisible', false)->set('estimate', '6')->set('scope', 'out')->set('price', '120.50')
        ->set('assigneeIds', [$s['member']->id, $s['outsider']->id])->set('labelIds', [$label->id])
        ->call('save')->assertHasNoErrors()->assertSet('editing', false);

    $t = $s['task']->fresh();
    expect($t)->title->toBe('Renamed')->priority->toBe(2)->board_column_id->toBe($col->id)->client_visible->toBeFalse();
    expect($t->deadline->toDateString())->toBe('2030-03-03');
    expect($t->price)->toBe(120.5);
    expect($t->assignees()->pluck('users.id')->all())->toBe([$s['member']->id]); // outsider is not on the project
    expect($t->activities()->where('field', 'deadline')->first())->reason->toBe('Client asked');
    expect($t->activities()->where('field', 'board_column_id')->first()->new)->toBe($col->name);

    tpPage($s)->assertSee('Mar 3, 2030')->assertSee('Renamed');
});

it('validates task money and dates on the page', function () {
    $s = tpSetup();
    tpPage($s)->call('edit')->set('price', '-5')->set('estimate', '-1')->set('deadline', 'nope')->call('save')
        ->assertHasErrors(['price', 'estimate', 'deadline']);
    tpPage($s)->call('edit')->set('price', '99999999')->call('save')->assertHasErrors('price');
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
    tpPage($s, $s['member'])->call('edit')->assertForbidden();
    $s['task']->assignees()->attach($s['member']->id);
    tpPage($s, $s['member'])->call('edit')->set('title', 'By assignee')->call('save')->assertHasNoErrors();
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
    tpPage($s)->set('commentBody', 'Hello')->call('postComment', 'task')->assertSet('commentBody', '');
    $root = $s['task']->comments()->first();
    tpPage($s, $s['member'])->call('startReply', 'task', $root->id)->set('replyBody', 'Hi back')->call('postReply');
    expect($s['task']->comments()->where('parent_id', $root->id)->first())->body->toBe('Hi back');
    tpPage($s)->set('commentBody', '')->call('postComment', 'task')->assertHasErrors('commentBody');
    tpPage($s)->assertSee('Hello')->assertSee('Hi back');
});

it('shows a deadline change in the timeline', function () {
    $s = tpSetup();
    tpPage($s)->call('edit')->set('deadline', '2031-01-02')->call('save');
    tpPage($s)->assertSee('changed deadline from none to 2031-01-02');
});

// ── subtask panel ────────────────────────────────────────────────────────────

it('adds subtasks and opens the detail panel inside the task page', function () {
    $s = tpSetup();
    $l = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $c = tpPage($s)->set('newSubtask', 'Cart API')->call('addSubtask')->assertSet('newSubtask', '');
    $sub = $s['task']->subtasks()->first();
    expect($sub->title)->toBe('Cart API');

    $c->call('openSubtask', $sub->id)->assertSee('data-test="subtask-panel"', false)->assertSee('No description yet.')
        ->call('editSubtask')->set('subDescription', "**bold** text")->set('subAssigneeIds', [$s['member']->id])->set('subLabelIds', [$l->id])
        ->call('saveSubtask')->assertHasNoErrors()->assertSet('subEditing', false)
        ->assertSee('<strong>bold</strong>', false)->assertSee($s['member']->name)->assertSee('Bug');

    $c->set('subCommentBody', 'Sub note')->call('postComment', 'subtask')->assertSee('Sub note');
    $root = $sub->comments()->first();
    $c->call('startReply', 'subtask', $root->id)->set('replyBody', 'Sub reply')->call('postReply')->assertSee('Sub reply');
    expect($sub->fresh()->description)->toBe('**bold** text');
    expect($sub->activities()->pluck('field')->filter()->unique()->sort()->values()->all())->toBe(['assignees', 'description', 'labels']);
    // comments went to the subtask, not the task
    expect($s['task']->comments()->count())->toBe(0);

    $c->call('closeSubtask')->assertDontSee('data-test="subtask-panel"', false);
});

it('will not open or delete a subtask of another task', function () {
    $s = tpSetup();
    $other = Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Other', 'status' => 'published']);
    $foreign = Subtask::create(['task_id' => $other->id, 'title' => 'Foreign']);
    expect(fn () => tpPage($s)->call('openSubtask', $foreign->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => tpPage($s)->call('deleteSubtask', $foreign->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(Subtask::find($foreign->id))->not->toBeNull();
});

it('stores uploads for the task, or the subtask while its editor is open', function () {
    $s = tpSetup();
    $sub = Subtask::create(['task_id' => $s['task']->id, 'title' => 'S']);
    $c = tpPage($s)->set('upload', UploadedFile::fake()->image('a.png'))->call('storeUpload');
    expect($s['task']->attachments()->count())->toBe(1)->and($sub->attachments()->count())->toBe(0);

    $c->call('openSubtask', $sub->id)->call('editSubtask')->set('upload', UploadedFile::fake()->image('b.png'))->call('storeUpload');
    expect($sub->attachments()->count())->toBe(1);
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
    Livewire::actingAs($s['admin'])->test(Manager::class, ['project' => $s['project']])->assertSee('Preview');
    Livewire::actingAs($s['admin'])->test(ProjectShow::class, ['project' => $s['project']])->set('tab', 'modules')->assertSee('newModuleDescription', false)->assertSee('Preview');
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

it('creates a module with a description from the manager', function () {
    $s = tpSetup();
    Livewire::actingAs($s['admin'])->test(Manager::class, ['project' => $s['project']])
        ->set('newName', 'Reports')->set('newDescription', '**x**')->call('createModule')->assertSet('newDescription', '');
    expect(Module::where('name', 'Reports')->first()->description)->toBe('**x**');
});

it('prompts managers to add a module description inline when it is empty', function () {
    $s = tpSetup();
    Livewire::actingAs($s['admin'])->test(App\Livewire\Modules\Show::class, ['project' => $s['project'], 'module' => $s['module']])
        ->assertSee('data-test="empty-description"', false)
        ->set('description', 'Hello **there**')->call('saveDescription');
    expect($s['module']->fresh()->description)->toBe('Hello **there**');
});

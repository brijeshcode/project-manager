<?php

use App\Enums\AccessScope;
use App\Livewire\Modules\Show;
use App\Livewire\Portal\ProjectView;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Support\Markdown;
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

/** Project with a module, an admin, a member, a non-member, and two client users. */
function modSetup(): array
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

function modPage(array $s, ?User $as = null)
{
    return Livewire::actingAs($as ?? $s['admin'])->test(Show::class, ['project' => $s['project'], 'module' => $s['module']]);
}

// ── activity log ──────────────────────────────────────────────────────────────

it('logs every tracked field with who, when, old and new', function () {
    $s = modSetup();
    $this->actingAs($s['admin']);
    $m = $s['module'];
    $m->update(['description' => 'Old text']);
    Activity::query()->delete();
    $this->travelTo(now()->addHour());

    $m->update(['name' => 'Payments', 'description' => 'New text', 'status_override' => 'on_hold', 'deadline' => '2026-12-01', 'client_visible' => true]);

    $rows = $m->activities()->get()->keyBy('field');
    expect($rows->keys()->sort()->values()->all())->toBe(['client_visible', 'deadline', 'description', 'name', 'status_override']);
    expect($rows['name'])->old->toBe('Checkout')->new->toBe('Payments')->event->toBe('changed')->user_id->toBe($s['admin']->id);
    expect($rows['name']->created_at->toDateTimeString())->toBe(now()->toDateTimeString());
    expect($rows['description'])->old->toBe('Old text')->new->toBe('New text');
    expect($rows['status_override'])->old->toBeNull()->new->toBe('on_hold');
    expect($rows['deadline'])->old->toBeNull()->new->toBe('2026-12-01');
    expect($rows['client_visible'])->old->toBe('no')->new->toBe('yes');
});

it('does not log untracked or unchanged saves', function () {
    $s = modSetup();
    $s['module']->update(['position' => 5]);
    $s['module']->update(['name' => 'Checkout']);

    expect($s['module']->activities()->count())->toBe(0);
});

it('stores an optional reason with a deadline change, and works without one', function () {
    $s = modSetup();
    $this->actingAs($s['admin']);
    $m = $s['module'];
    $m->update(['deadline' => '2026-12-01']);
    $m->activityReason = 'Client asked for more time';
    $m->update(['deadline' => '2027-01-15']);

    $a = $m->activities()->get();
    expect($a->first())->old->toBe('2026-12-01')->new->toBe('2027-01-15')->reason->toBe('Client asked for more time');
    expect($a->last()->reason)->toBeNull();
});

it('logs assigned and unassigned', function () {
    $s = modSetup();
    $this->actingAs($s['admin']);
    $m = $s['module'];
    $m->syncAssignees([$s['member']->id, $s['admin']->id]);
    $m->syncAssignees([$s['admin']->id]);

    $rows = $m->activities()->reorder('id')->get();
    expect($rows->pluck('event')->all())->toBe(['assigned', 'assigned', 'unassigned']);
    expect($rows->last())->old->toBe($s['member']->name)->field->toBe('assignees')->user_id->toBe($s['admin']->id);
    expect($rows->first()->summary())->toBe('assigned '.$s['member']->name);
    expect($m->assignees()->pluck('users.id')->all())->toBe([$s['admin']->id]);
});

it('logs labels added and removed', function () {
    $s = modSetup();
    $this->actingAs($s['admin']);
    $bug = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $ux = Label::create(['name' => 'UX', 'color' => '#0f0']);
    $s['module']->syncLabels([$bug->id, $ux->id]);
    $s['module']->syncLabels([$ux->id]);

    $rows = $s['module']->activities()->reorder('id')->get();
    expect($rows->pluck('event')->all())->toBe(['label_added', 'label_added', 'label_removed']);
    expect($rows->last())->old->toBe('Bug');
    expect($rows->last()->summary())->toBe('removed the label Bug');
});

it('keeps task labels working on the shared labelables table', function () {
    $s = modSetup();
    $task = Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'T', 'status' => 'published']);
    $label = Label::create(['name' => 'Bug', 'color' => '#f00']);
    $task->labels()->sync([$label->id]);

    expect($task->fresh()->labels()->pluck('labels.name')->all())->toBe(['Bug']);
    expect($label->tasks()->count())->toBe(1);
    $s['module']->syncLabels([$label->id]);
    expect($task->labels()->count())->toBe(1);
    expect($s['module']->labels()->count())->toBe(1);
});

// ── status override ───────────────────────────────────────────────────────────

it('derives status and only allows on_hold or completed overrides', function () {
    $s = modSetup();
    $m = $s['module'];
    expect($m->status())->toBe('pending');

    $m->setStatusOverride('on_hold');
    expect($m->fresh()->status())->toBe('on_hold');
    $m->setStatusOverride('completed');
    expect($m->fresh()->status())->toBe('completed');
    $m->setStatusOverride(null);
    expect($m->fresh()->status())->toBe('pending');

    expect(fn () => $m->setStatusOverride('working'))->toThrow(InvalidArgumentException::class);
    expect($m->fresh()->status_override)->toBeNull();
});

it('marks completed even with open tasks, keeps it until reopened, and logs it', function () {
    $s = modSetup();
    Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Open', 'status' => 'published']);

    expect($s['module']->openTaskCount())->toBe(1);
    modPage($s)->call('markCompleted')->assertSee('Completed');
    expect($s['module']->fresh()->status())->toBe('completed');
    expect($s['module']->activities()->first())->field->toBe('status_override')->new->toBe('completed');

    modPage($s)->call('reopen');
    expect($s['module']->fresh()->status())->toBe('pending');
    modPage($s)->call('putOnHold');
    expect($s['module']->fresh()->status())->toBe('on_hold');
});

// ── markdown ──────────────────────────────────────────────────────────────────

it('renders markdown with lists, links and task lists', function () {
    $html = Markdown::render("- one\n- [ ] todo\n\n[site](https://example.com) **bold**");

    expect($html)->toContain('<li>one</li>')->toContain('type="checkbox"')->toContain('<a href="https://example.com">site</a>')->toContain('<strong>bold</strong>');
});

it('neutralises script tags, html and javascript links', function () {
    $html = Markdown::render("<script>alert(1)</script><img src=x onerror=alert(2)>\n\n[click](javascript:alert(3)) [d](data:text/html;base64,AAAA)");

    expect($html)->not->toContain('<script')->not->toContain('onerror')->not->toContain('<img')
        ->not->toContain('javascript:')->not->toContain('data:text');

    $view = (string) $this->blade('<x-ui.markdown :text="$t" />', ['t' => "<script>x()</script>

**ok**"]);
    expect($view)->toContain('<strong>ok</strong>')->not->toContain('<script');
});

// ── uploads ───────────────────────────────────────────────────────────────────

it('stores an uploaded image as an attachment and returns the markdown target', function () {
    $s = modSetup();
    $c = modPage($s)->set('upload', UploadedFile::fake()->image('shot.png', 20, 20))->call('storeUpload');
    $a = Attachment::first();

    expect($a)->name->toBe('shot.png')->attachable_id->toBe($s['module']->id)->user_id->toBe($s['admin']->id);
    Storage::disk('local')->assertExists($a->path);
    expect($a->url())->toBe('/attachments/'.$a->id.'/download');
    expect($a->isInlineImage())->toBeTrue();
});

it('rejects executables, scripts, svg, and files over 10MB', function () {
    $s = modSetup();
    $c = modPage($s);
    foreach (['evil.exe', 'x.php', 'x.sh', 'x.html', 'x.svg', 'x.js'] as $name) {
        $c->set('upload', UploadedFile::fake()->create($name, 5))->call('storeUpload');
    }
    $c->set('upload', UploadedFile::fake()->create('big.pdf', 10241))->call('storeUpload');

    expect(Attachment::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('attachments'))->toBe([]);

    $c->set('upload', UploadedFile::fake()->create('ok.pdf', 100))->call('storeUpload');
    expect(Attachment::count())->toBe(1);
});

it('lets only authorised users download attachments', function () {
    $s = modSetup();
    $file = UploadedFile::fake()->create('spec.pdf', 10);
    $path = $file->store('attachments/2026/11', 'local');
    $a = $s['module']->attachments()->create(['user_id' => $s['admin']->id, 'path' => $path, 'name' => 'spec.pdf', 'mime' => 'application/pdf', 'size' => 10]);

    $this->get($a->url())->assertRedirect('/login');
    $this->actingAs($s['admin'])->get($a->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($s['member'])->get($a->url())->assertOk();
    $this->actingAs($s['outsider'])->get($a->url())->assertForbidden();
    $this->actingAs($s['c1'])->get($a->url())->assertForbidden();
    $this->actingAs($s['foreign'])->get($a->url())->assertForbidden();
});

it('deletes the stored file with the attachment and module', function () {
    $s = modSetup();
    $path = UploadedFile::fake()->create('a.pdf', 1)->store('attachments/x', 'local');
    $s['module']->attachments()->create(['path' => $path, 'name' => 'a.pdf', 'size' => 1]);
    $s['module']->delete();

    Storage::disk('local')->assertMissing($path);
});

// ── page + timeline ───────────────────────────────────────────────────────────

it('serves the module page to members and not to outsiders or other projects', function () {
    $s = modSetup();
    $url = route('modules.show', [$s['project'], $s['module']]);
    $this->actingAs($s['member'])->get($url)->assertOk()->assertSee('Checkout');
    $this->actingAs($s['outsider'])->get($url)->assertForbidden();

    $p2 = Project::create(['client_id' => $s['client']->id, 'name' => 'Other', 'status' => 'active']);
    $this->actingAs($s['admin'])->get(route('modules.show', [$p2, $s['module']]))->assertNotFound();
});

it('links the module page from the project Modules tab', function () {
    $s = modSetup();
    $this->actingAs($s['admin'])->get(route('projects.show', ['project' => $s['project'], 'tab' => 'modules']))
        ->assertSee(route('modules.show', [$s['project'], $s['module']]), false);
});

it('shows header, description, progress, tasks and sidebar', function () {
    $s = modSetup();
    $s['module']->update(['description' => "- first\n- second", 'deadline' => '2030-05-05']);
    Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Build form', 'status' => 'published']);

    modPage($s)->assertSee('Checkout')->assertSee('Pending')->assertSee('<li>first</li>', false)
        ->assertSee('Build form')->assertSee('May 5, 2030')->assertSee('No one assigned')->assertSee('Progress');
});

it('merges comments, activity and budget changes by time, with threaded replies', function () {
    $s = modSetup();
    $this->actingAs($s['admin']);
    $m = $s['module'];
    $t = now()->startOfMinute();

    $comment = $m->addComment($s['admin'], 'First **comment**');
    $comment->forceFill(['created_at' => $t->copy()->addMinute()])->save();
    $reply = $m->addComment($s['member'], 'A reply', $comment->id);
    $reply->forceFill(['created_at' => $t->copy()->addMinutes(2)])->save();
    $m->update(['name' => 'Payments']);
    $m->activities()->first()->forceFill(['created_at' => $t->copy()->addMinutes(3)])->save();
    $m->updateBudget(['budget_hours' => '10'], $s['admin'], '');
    $m->budgetChanges()->first()->forceFill(['created_at' => $t->copy()->addMinutes(5)])->save();

    $c = modPage($s);
    $tl = $c->instance()->timeline();
    expect($tl->pluck('kind')->all())->toBe(['comment', 'activity', 'budget']); // oldest first; reply sits inside its thread
    expect($tl->first()['m']->replies->pluck('body')->all())->toBe(['A reply']);
    $c->assertSeeInOrder(['First', 'A reply', 'changed the name from Checkout to Payments', 'changed the budget']);
});

it('posts comments and threaded replies from the page', function () {
    $s = modSetup();
    modPage($s)->set('commentBody', 'Hello')->call('postComment')->assertSet('commentBody', '');
    $root = $s['module']->comments()->first();
    modPage($s, $s['member'])->call('startReply', $root->id)->set('replyBody', 'Hi back')->call('postReply');
    modPage($s)->set('commentBody', '')->call('postComment')->assertHasErrors('commentBody');

    $reply = Comment::where('parent_id', $root->id)->first();
    expect($reply)->body->toBe('Hi back')->author_id->toBe($s['member']->id);
    // reply to a reply lands on the root
    $s['module']->addComment($s['admin'], 'nested', $reply->id);
    expect(Comment::where('parent_id', $root->id)->count())->toBe(2);
});

it('edits every module field in place and logs the changes, deadline reason optional', function () {
    $s = modSetup();
    $label = Label::create(['name' => 'Bug', 'color' => '#f00']);
    modPage($s)->call('editTitle')->set('title', 'Payments')->call('saveTitle')->assertHasNoErrors()->assertSet('editingTitle', false)
        ->call('editDescription')->set('description', "- [ ] a\n- b")->call('saveDescription')
        ->call('openPanel', 'deadline')->set('deadline', '2030-01-01')->call('savePanel')->assertSet('panel', null)
        ->call('openPanel', 'visibility')->set('clientVisible', true)->call('savePanel')
        ->call('openPanel', 'assignees')->set('assigneeIds', [$s['member']->id, $s['outsider']->id])->call('savePanel')
        ->call('openPanel', 'labels')->set('labelIds', [$label->id])->call('savePanel');

    $m = $s['module']->fresh();
    expect($m)->name->toBe('Payments')->client_visible->toBeTrue();
    expect($m->assignees()->pluck('users.id')->all())->toBe([$s['member']->id]); // outsider is not on the project
    expect($m->labels()->count())->toBe(1);
    expect($m->activities()->pluck('field')->unique()->sort()->values()->all())->toBe(['assignees', 'client_visible', 'deadline', 'description', 'labels', 'name']);

    modPage($s)->call('openPanel', 'deadline')->set('deadline', '2030-02-01')->set('deadlineReason', 'Scope grew')->call('savePanel');
    expect($m->activities()->where('field', 'deadline')->first())->new->toBe('2030-02-01')->reason->toBe('Scope grew');
});

it('keeps the required reason on budget changes and logs them once', function () {
    $s = modSetup();
    $s['module']->update(['billing_mode' => 'hourly']);
    $s['module']->updateBudget(['budget_hours' => '10', 'hourly_rate' => '50.00'], $s['admin'], '');

    modPage($s)->call('openPanel', 'budget')->set('hours', '20')->call('savePanel')->assertHasErrors('budgetReason')->assertSet('panel', 'budget');
    expect($s['module']->fresh()->budget_hours)->toBe('10.00');

    modPage($s)->call('openPanel', 'budget')->set('hours', '20')->set('budgetReason', 'Scope grew')->call('savePanel')->assertHasNoErrors()->assertSet('panel', null);
    expect($s['module']->fresh()->budget_hours)->toBe('20.00');
    expect($s['module']->budgetChanges()->where('reason', 'Scope grew')->where('field', 'budget_hours')->count())->toBe(1);

    $s['module']->updateBudget(['deadline' => '2031-01-01'], $s['admin'], '');
    expect($s['module']->activities()->where('field', 'deadline')->count())->toBe(0); // the budget row covers it
});
it('forbids editing for users who cannot update the project', function () {
    $s = modSetup();
    modPage($s, $s['outsider'])->assertForbidden();
});

// ── client portal ─────────────────────────────────────────────────────────────

function modPortal(array $s, User $as)
{
    return Livewire::actingAs($as)->test(ProjectView::class, ['project' => $s['project']]);
}

it('lists only client-visible modules in the portal', function () {
    $s = modSetup();
    Module::create(['project_id' => $s['project']->id, 'name' => 'Secret module', 'position' => 2]);
    modPortal($s, $s['c1'])->assertDontSee('Checkout')->assertDontSee('Secret module');

    $s['module']->update(['client_visible' => true]);
    modPortal($s, $s['c1'])->assertSee('Checkout')->assertSee('Pending')->assertDontSee('Secret module');
});

it('shows a client their own comments, replies in their threads, and hides everything else', function () {
    $s = modSetup();
    $m = $s['module'];
    $m->update(['client_visible' => true]);
    $own = $m->addComment($s['c1'], 'Client one question');
    $m->addComment($s['admin'], 'Staff reply to client one', $own->id);
    $m->addComment($s['admin'], 'Internal staff note');
    $theirs = $m->addComment($s['c2'], 'Client two question');
    $m->addComment($s['admin'], 'Staff reply to client two', $theirs->id);

    $page = modPortal($s, $s['c1']);
    $page->assertSee('Client one question')->assertSee('Staff reply to client one')
        ->assertDontSee('Internal staff note')->assertDontSee('Client two question')->assertDontSee('Staff reply to client two');

    modPortal($s, $s['c2'])->assertSee('Client two question')->assertSee('Staff reply to client two')
        ->assertDontSee('Client one question')->assertDontSee('Internal staff note');

    expect($m->commentThreads($s['c1'])->pluck('body')->all())->toBe(['Client one question']);
    expect($m->commentThreads($s['c1'])->first()->replies->pluck('body')->all())->toBe(['Staff reply to client one']);
    expect($m->commentThreads()->count())->toBe(3); // staff see all threads
});

it('lets clients comment and reply on modules, only in threads they can see', function () {
    $s = modSetup();
    $m = $s['module'];
    $m->update(['client_visible' => true]);

    modPortal($s, $s['c1'])->set('moduleBody.'.$m->id, 'Any news?')->call('addModuleComment', $m->id)->assertHasNoErrors();
    $root = $m->comments()->first();
    expect($root)->author_id->toBe($s['c1']->id)->parent_id->toBeNull();

    modPortal($s, $s['c1'])->set('replyBody.'.$root->id, 'Thanks')->call('reply', 'module', $m->id, $root->id);
    expect(Comment::where('parent_id', $root->id)->first())->body->toBe('Thanks');

    $internal = $m->addComment($s['admin'], 'internal');
    expect(fn () => modPortal($s, $s['c1'])->set('replyBody.'.$internal->id, 'sneaky')->call('reply', 'module', $m->id, $internal->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $hidden = Module::create(['project_id' => $s['project']->id, 'name' => 'Hidden', 'position' => 2]);
    modPortal($s, $s['c1'])->set('moduleBody.'.$hidden->id, 'x')->call('addModuleComment', $hidden->id)->assertForbidden();
});

it('lets clients comment and reply on tasks with the same visibility rule', function () {
    $s = modSetup();
    $task = Task::create(['module_id' => $s['module']->id, 'creator_id' => $s['admin']->id, 'title' => 'Shared task', 'status' => 'published', 'client_visible' => true]);

    modPortal($s, $s['c1'])->set('commentBody', 'Task question')->call('addComment', $task->id);
    $root = $task->comments()->first();
    $task->addComment($s['admin'], 'Staff answer', $root->id);
    $task->addComment($s['admin'], 'Internal task note');
    modPortal($s, $s['c1'])->set('replyBody.'.$root->id, 'Great')->call('reply', 'task', $task->id, $root->id);

    modPortal($s, $s['c1'])->assertSee('Task question')->assertSee('Staff answer')->assertSee('Great')->assertDontSee('Internal task note');
    modPortal($s, $s['c2'])->assertDontSee('Task question')->assertDontSee('Staff answer');
    expect($task->commentThreads($s['c1'])->first()->replies->pluck('body')->all())->toBe(['Staff answer', 'Great']);
});

it('never shows activity, attachments or the timeline to clients', function () {
    $s = modSetup();
    $m = $s['module'];
    $m->update(['client_visible' => true]);
    $this->actingAs($s['admin']);
    $m->update(['name' => 'Renamed publicly']);
    $m->syncLabels([Label::create(['name' => 'SecretLabel', 'color' => '#000'])->id]);
    $m->attachments()->create(['path' => 'attachments/x/secret-spec.pdf', 'name' => 'secret-spec.pdf', 'size' => 1]);
    $m->updateBudget(['budget_hours' => '5'], $s['admin'], '');

    $page = modPortal($s, $s['c1'])->assertSee('Renamed publicly');
    $page->assertDontSee('secret-spec.pdf')->assertDontSee('changed name')->assertDontSee('SecretLabel')
        ->assertDontSee('changed budget')->assertDontSee('Timeline');

    // and the staff module page is closed to clients entirely
    $this->actingAs($s['c1'])->get(route('modules.show', [$s['project'], $m]))->assertForbidden();
});

it('keeps another client out of the project portal', function () {
    $s = modSetup();
    $s['module']->update(['client_visible' => true]);
    Livewire::actingAs($s['foreign'])->test(ProjectView::class, ['project' => $s['project']])->assertForbidden();
});

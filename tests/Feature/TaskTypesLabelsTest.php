<?php

use App\Enums\AccessScope;
use App\Livewire\Settings\Labels as LabelsPage;
use App\Livewire\Settings\TaskTypes as TypesPage;
use App\Livewire\Subtasks\Show as SubtaskShow;
use App\Livewire\Tasks\Form as TaskForm;
use App\Livewire\Tasks\Show as TaskShow;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxonomySeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(TaxonomySeeder::class);
    $this->withoutVite();
});

function ttSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD', 'billing_mode' => 'hourly']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $module = Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'position' => 1]);
    $mk = fn (string $role) => tap(User::factory()->create(), fn ($u) => $u->assignRole($role));
    $admin = $mk('super_admin');
    $pm = $mk('project_manager');
    $dev = $mk('developer');
    foreach ([$pm, $dev] as $u) {
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    }
    $task = Task::create(['module_id' => $module->id, 'creator_id' => $admin->id, 'title' => 'Build form', 'status' => 'published']);
    $sub = Subtask::create(['task_id' => $task->id, 'title' => 'Wire API', 'estimate_hours' => 1]);

    return compact('client', 'project', 'module', 'admin', 'pm', 'dev', 'task', 'sub');
}

// ── seeders ──────────────────────────────────────────────────────────────────

it('seeds default types and labels once, keeping later edits', function () {
    expect(TaskType::orderBy('position')->pluck('name')->all())->toBe(['Task', 'Issue', 'Feature', 'Bug', 'Change']);
    expect(Label::orderBy('id')->pluck('name')->all())->toBe(['Urgent', 'Blocked', 'Needs review', 'Design', 'Frontend', 'Backend', 'Documentation', 'Question', 'Production', 'Staging', 'Development']);
    expect(TaskType::where('name', 'Bug')->first())->color->toBe('#ef4444')->icon->toBe('bug')->is_system->toBeTrue();
    expect(Label::where('name', 'Urgent')->value('icon'))->not->toBeNull();

    TaskType::where('name', 'Bug')->update(['color' => '#000000']);
    $this->seed(TaxonomySeeder::class);
    expect(TaskType::count())->toBe(5)->and(Label::count())->toBe(11)->and(TaskType::where('name', 'Bug')->value('color'))->toBe('#000000');
});

it('is part of the main database seeder', function () {
    TaskType::query()->delete();
    Label::query()->delete();
    $this->seed();
    expect(TaskType::count())->toBe(5)->and(Label::count())->toBe(11);
});

// ── default type, task page, activity ────────────────────────────────────────

it('gives new tasks the Task type by default and shows it on the task page', function () {
    $s = ttSetup();
    expect($s['task']->type->name)->toBe('Task');
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $s['task']])->assertSeeHtml('data-test="type-badge"')->assertSeeHtml('data-type="Task"');
});

it('changes the type from the sidebar and logs it with names', function () {
    $s = ttSetup();
    $bug = TaskType::where('name', 'Bug')->first();
    $feature = TaskType::where('name', 'Feature')->first();
    $s['task']->update(['task_type_id' => $bug->id]);
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $s['task']->fresh()])
        ->call('openPanel', 'type')->set('typeId', $feature->id)->call('savePanel')
        ->assertSet('panel', null)->assertSee('changed the type from Bug to Feature');
    expect($s['task']->fresh()->task_type_id)->toBe($feature->id);
    expect($s['task']->activities()->where('field', 'task_type_id')->first()->summary())->toBe('changed the type from Bug to Feature');
});

it('rejects an unknown type from the sidebar', function () {
    $s = ttSetup();
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $s['task']])->call('openPanel', 'type')->set('typeId', 9999)->call('savePanel')->assertHasErrors('typeId');
});

it('sets the type on the task form, defaulting to Task', function () {
    $s = ttSetup();
    $issue = TaskType::where('name', 'Issue')->first();
    Livewire::actingAs($s['admin'])->test(TaskForm::class)->set('module_id', $s['module']->id)->set('title', 'T1')->set('status', 'published')->call('save')->assertHasNoErrors();
    expect(Task::where('title', 'T1')->first()->type->name)->toBe('Task');
    Livewire::actingAs($s['admin'])->test(TaskForm::class)->set('module_id', $s['module']->id)->set('title', 'T2')->set('status', 'published')->set('task_type_id', $issue->id)->call('save');
    expect(Task::where('title', 'T2')->first()->task_type_id)->toBe($issue->id);
    Livewire::actingAs($s['admin'])->test(TaskForm::class, ['task' => $s['task']])->set('task_type_id', $issue->id)->call('save');
    expect($s['task']->fresh()->task_type_id)->toBe($issue->id);
});

it('carries the type through copy', function () {
    $s = ttSetup();
    $bug = TaskType::where('name', 'Bug')->first();
    $s['task']->update(['task_type_id' => $bug->id]);
    $this->actingAs($s['admin']);
    expect($s['task']->copyTo($s['module']->id, $s['admin'], false)->task_type_id)->toBe($bug->id);
    Livewire::withQueryParams(['copy' => $s['task']->id])->actingAs($s['admin'])->test(TaskForm::class)->assertSet('task_type_id', $bug->id);
});

it('converts a subtask with the chosen type, default Task', function () {
    $s = ttSetup();
    $feature = TaskType::where('name', 'Feature')->first();
    Livewire::actingAs($s['admin'])->test(SubtaskShow::class, ['subtask' => $s['sub']])
        ->set('confirmingConvert', true)->assertSee('data-test="convert-type"', false)
        ->assertSet('convertType', TaskType::default()->id)->set('convertType', $feature->id)->call('convertToTask');
    expect(Task::where('title', 'Wire API')->first()->type->name)->toBe('Feature');

    $sub2 = Subtask::create(['task_id' => $s['task']->id, 'title' => 'Second', 'estimate_hours' => 1]);
    $t = $sub2->convertToTask($s['admin']);
    expect($t->type->name)->toBe('Task');
});

// ── icons: emoji vs name, escaping ───────────────────────────────────────────

it('renders an icon name as svg and anything else as escaped text', function () {
    $svg = Blade::render('<x-ui.glyph value="bug" />');
    expect($svg)->toContain('<svg');
    $emoji = Blade::render('<x-ui.glyph value="🔥" />');
    expect($emoji)->toContain('🔥')->not->toContain('<svg');
    $xss = Blade::render('<x-ui.glyph :value="$v" />', ['v' => '<script>alert(1)</script>']);
    expect($xss)->not->toContain('<script>')->toContain('&lt;script&gt;');
    expect(Blade::render('<x-ui.glyph value="" />'))->toBe('');
});

it('shows type and label icons wherever they are listed', function () {
    $s = ttSetup();
    $l = Label::where('name', 'Urgent')->first();
    $s['task']->syncLabels([$l->id]);
    $html = Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $s['task']])->html();
    expect($html)->toContain('data-label="Urgent"')->toContain('data-type="Task"');
});

it('rejects markup in an icon', function () {
    $s = ttSetup();
    Livewire::actingAs($s['admin'])->test(LabelsPage::class)->call('create')->set('name', 'X')->set('icon', '<b>')->call('save')->assertHasErrors('icon');
    Livewire::actingAs($s['admin'])->test(LabelsPage::class)->call('create')->set('name', 'X')->set('icon', 'this-is-way-too-long-for-an-icon')->call('save')->assertHasErrors('icon');
    expect(Label::where('name', 'X')->exists())->toBeFalse();
});

// ── permissions ──────────────────────────────────────────────────────────────

it('lets only the super admin open the management pages', function () {
    $s = ttSetup();
    foreach (['settings.task-types', 'settings.labels'] as $route) {
        $this->actingAs($s['admin'])->get(route($route))->assertOk();
        $this->actingAs($s['pm'])->get(route($route))->assertForbidden();
        $this->actingAs($s['dev'])->get(route($route))->assertForbidden();
    }
    auth()->logout();
    $this->get(route('settings.labels'))->assertRedirect();
});

it('blocks every management action for other roles', function () {
    $s = ttSetup();
    $type = TaskType::first();
    $label = Label::first();
    // even a component mounted as admin then driven by a non-admin session is refused per action
    // each call on a fresh component mounted as admin, then driven by a non-admin session
    $attempt = function (string $page, string $method, array $args) use ($s) {
        $c = Livewire::actingAs($s['admin'])->test($page);
        auth()->setUser($s['pm']);
        $c->call($method, ...$args)->assertForbidden();
    };
    $attempt(TypesPage::class, 'create', []);
    $attempt(TypesPage::class, 'reorder', [[$type->id]]);
    $attempt(TypesPage::class, 'confirmDelete', [$type->id]);
    $attempt(LabelsPage::class, 'create', []);
    $attempt(LabelsPage::class, 'confirmDelete', [$label->id]);
    expect(Label::count())->toBe(11)->and(TaskType::count())->toBe(5);
});

it('has no inline create-label input any more', function () {
    $s = ttSetup();
    foreach ([[TaskShow::class, ['task' => $s['task']]], [SubtaskShow::class, ['subtask' => $s['sub']]]] as [$cls, $params]) {
        $c = Livewire::actingAs($s['admin'])->test($cls, $params)->call('openPanel', 'labels');
        $c->assertDontSee('New label…')->assertSee('Manage labels');
        expect(method_exists($c->instance(), 'addLabel'))->toBeFalse();
        Livewire::actingAs($s['pm'])->test($cls, $params)->call('openPanel', 'labels')->assertDontSee('Manage labels');
    }
});

it('still lets members assign existing labels', function () {
    $s = ttSetup();
    $l = Label::where('name', 'Design')->first();
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $s['task']])->call('openPanel', 'labels')->set('labelIds', [$l->id])->call('savePanel');
    expect($s['task']->labels()->pluck('labels.id')->all())->toBe([$l->id]);
});

// ── type management ──────────────────────────────────────────────────────────

it('creates, edits and reorders task types', function () {
    $s = ttSetup();
    $c = Livewire::actingAs($s['admin'])->test(TypesPage::class)
        ->call('create')->set('name', 'Spike')->set('color', '#112233')->set('icon', '🔥')->call('save')->assertHasNoErrors()->assertSet('editing', null);
    $spike = TaskType::where('name', 'Spike')->first();
    expect($spike)->color->toBe('#112233')->icon->toBe('🔥')->position->toBe(6)->is_system->toBeFalse();
    $c->assertSee('🔥');

    $c->call('edit', $spike->id)->set('name', 'Research')->set('icon', 'code')->call('save');
    expect($spike->fresh())->name->toBe('Research')->icon->toBe('code');

    $ids = TaskType::orderBy('position')->pluck('id')->reverse()->values()->all();
    $c->call('reorder', $ids);
    expect(TaskType::orderBy('position')->pluck('id')->all())->toBe($ids);
});

it('validates type name and colour', function () {
    $s = ttSetup();
    $c = Livewire::actingAs($s['admin'])->test(TypesPage::class)->call('create');
    $c->set('name', 'Bug')->set('color', '#112233')->call('save')->assertHasErrors('name');
    $c->set('name', 'Fresh')->set('color', 'red')->call('save')->assertHasErrors('color');
    $c->set('name', '')->set('color', '#112233')->call('save')->assertHasErrors('name');
});

it('deletes an unused type, and requires a replacement for a type in use', function () {
    $s = ttSetup();
    $c = Livewire::actingAs($s['admin'])->test(TypesPage::class);
    $change = TaskType::where('name', 'Change')->first();
    $c->call('confirmDelete', $change->id)->call('delete');
    expect(TaskType::find($change->id))->toBeNull();

    $custom = TaskType::create(['name' => 'Spike', 'color' => '#112233', 'position' => 9]);
    $s['task']->update(['task_type_id' => $custom->id]);
    $c->call('confirmDelete', $custom->id)->call('delete')->assertHasErrors('replacement');
    expect(TaskType::find($custom->id))->not->toBeNull();
    $bug = TaskType::where('name', 'Bug')->first();
    $c->set('replacement', $bug->id)->call('delete')->assertHasNoErrors();
    expect(TaskType::find($custom->id))->toBeNull()->and($s['task']->fresh()->task_type_id)->toBe($bug->id);
});

it('does not delete a built-in type while tasks use it, and always keeps one type', function () {
    $s = ttSetup();
    $task = TaskType::where('name', 'Task')->first();
    $c = Livewire::actingAs($s['admin'])->test(TypesPage::class);
    $c->call('confirmDelete', $task->id)->set('replacement', TaskType::where('name', 'Bug')->value('id'))->call('delete')->assertHasErrors('replacement');
    expect(TaskType::find($task->id))->not->toBeNull()->and($s['task']->fresh()->task_type_id)->toBe($task->id);

    $s['task']->update(['task_type_id' => null]);
    TaskType::where('id', '!=', $task->id)->delete();
    $c->call('confirmDelete', $task->id)->call('delete')->assertHasErrors('replacement');
    expect(TaskType::count())->toBe(1);
});

it('keeps tasks working when their type disappears', function () {
    $s = ttSetup();
    TaskType::find($s['task']->task_type_id)->delete();
    expect($s['task']->fresh()->task_type_id)->toBeNull();
    Livewire::actingAs($s['admin'])->test(TaskShow::class, ['task' => $s['task']->fresh()])->assertOk();
});

// ── label management ─────────────────────────────────────────────────────────

it('creates, edits and deletes labels, detaching them from items', function () {
    $s = ttSetup();
    $c = Livewire::actingAs($s['admin'])->test(LabelsPage::class)
        ->call('create')->set('name', 'Spike')->set('color', '#abcdef')->set('icon', '💡')->call('save')->assertHasNoErrors();
    $label = Label::where('name', 'Spike')->first();
    expect($label)->color->toBe('#abcdef')->icon->toBe('💡');
    $c->call('edit', $label->id)->set('icon', 'star')->set('name', 'Spiky')->call('save');
    expect($label->fresh())->name->toBe('Spiky')->icon->toBe('star');
    $c->call('create')->set('name', 'Spiky')->call('save')->assertHasErrors('name'); // unique

    $s['task']->syncLabels([$label->id]);
    $s['sub']->syncLabels([$label->id]);
    $c->call('confirmDelete', $label->id)->assertSee('2 items')->call('delete');
    expect(Label::find($label->id))->toBeNull()->and($s['task']->labels()->count())->toBe(0)->and($s['sub']->labels()->count())->toBe(0);
});

it('escapes an emoji or hostile name on the management pages', function () {
    $s = ttSetup();
    Label::create(['name' => '<img src=x onerror=1>', 'color' => '#ffffff', 'icon' => '🔥']);
    $html = Livewire::actingAs($s['admin'])->test(LabelsPage::class)->html();
    expect($html)->not->toContain('<img src=x')->toContain('🔥');
});

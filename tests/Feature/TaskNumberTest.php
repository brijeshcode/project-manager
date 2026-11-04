<?php

use App\Enums\AccessScope;
use App\Livewire\Portal\ProjectView as PortalView;
use App\Livewire\Projects\Form as ProjectForm;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Sprints\Panel as SprintPanel;
use App\Livewire\Tasks\Board;
use App\Livewire\Tasks\Show as TaskShow;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\BillableService;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
    $this->client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $this->project = Project::create(['client_id' => $this->client->id, 'name' => 'Northwind Storefront', 'status' => 'active', 'key' => 'nws']);
    $this->module = Module::create(['project_id' => $this->project->id, 'name' => 'Alpha', 'position' => 1]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

function tnTask(Module $m, User $by, string $title = 'T', array $a = []): Task
{
    return Task::create($a + ['module_id' => $m->id, 'creator_id' => $by->id, 'title' => $title, 'status' => 'published']);
}

it('numbers tasks sequentially per project, independent across projects, and fills project_id', function () {
    $other = Module::create(['project_id' => Project::create(['client_id' => $this->client->id, 'name' => 'Other'])->id, 'name' => 'X', 'position' => 1]);
    $m2 = Module::create(['project_id' => $this->project->id, 'name' => 'Beta', 'position' => 2]);
    $a = tnTask($this->module, $this->admin);
    $b = tnTask($m2, $this->admin);
    $o = tnTask($other, $this->admin);
    expect([$a->number, $b->number, $o->number])->toBe([1, 2, 1])
        ->and($a->project_id)->toBe($this->project->id)->and($o->project_id)->toBe($other->project_id)
        ->and($a->code())->toBe('NWS-1')->and($b->code())->toBe('NWS-2');
});

it('never reuses or changes a number, even after deletes', function () {
    $a = tnTask($this->module, $this->admin);
    $b = tnTask($this->module, $this->admin);
    $b->delete();
    $a->update(['title' => 'Renamed']);
    expect(tnTask($this->module, $this->admin)->number)->toBe(3)->and($a->fresh()->number)->toBe(1)
        ->and($this->project->fresh()->task_counter)->toBe(3);
});

it('hands out unique numbers across many creations', function () {
    $nums = collect(range(1, 40))->map(fn () => tnTask($this->module, $this->admin)->number);
    expect($nums->unique()->count())->toBe(40)->and($nums->max())->toBe(40);
    expect(fn () => Task::query()->insert(['project_id' => $this->project->id, 'number' => 5, 'module_id' => $this->module->id, 'creator_id' => $this->admin->id, 'title' => 'dup']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('gives a copy a new number and a converted subtask the next one; subtasks have none', function () {
    $t = tnTask($this->module, $this->admin);
    $copy = $t->copyTo($this->module->id, $this->admin, false);
    $sub = Subtask::create(['task_id' => $t->id, 'title' => 'Sub', 'estimate_hours' => 0]);
    expect(Schema::hasColumn('subtasks', 'number'))->toBeFalse();
    $conv = $sub->convertToTask($this->admin);
    expect([$t->number, $copy->number, $conv->number])->toBe([1, 2, 3]);
    $act = $conv->activities()->where('event', 'converted')->first();
    expect($act->summary())->toContain('NWS-1');
});

it('numbers tasks from quick-add and the portal', function () {
    Livewire::actingAs($this->admin)->test(ProjectShow::class, ['project' => $this->project])
        ->set("newTaskTitle.{$this->module->id}", 'Quick')->call('addTask', $this->module->id);
    expect(Task::where('title', 'Quick')->first()->code())->toBe('NWS-1');
    $cu = User::factory()->create(['client_id' => $this->client->id]);
    $cu->assignRole('client');
    Livewire::actingAs($cu)->test(PortalView::class, ['project' => $this->project])->set('newTitle', 'From client')->call('createTask');
    expect(Task::where('title', 'From client')->first()->code())->toBe('NWS-2');
});

it('derives a unique key from the name and lets it be edited with validation', function () {
    $mk = fn ($n, $k = null) => Project::create(['client_id' => $this->client->id, 'name' => $n] + ($k ? ['key' => $k] : []));
    expect($mk('Customer Portal Revamp')->key)->toBe('CPR')
        ->and($mk('Inventory API')->key)->toBe('INV')
        ->and($mk('Inventory API')->key)->toBe('INV2')
        ->and($mk('Go!')->key)->toBe('GOX')
        ->and($mk('Big Apple Pie Shop Online Store')->key)->toBe('BAPSO');
    $form = fn () => Livewire::actingAs($this->admin)->test(ProjectForm::class, ['project' => $this->project]);
    $form()->assertSet('key', 'NWS')->set('key', 'x')->call('save')->assertHasErrors('key');
    $form()->set('key', 'TOOLONG')->call('save')->assertHasErrors('key');
    $form()->set('key', 'inv')->call('save')->assertHasErrors('key'); // taken (uppercased)
    $form()->set('key', 'shop')->call('save')->assertHasNoErrors();
    expect($this->project->fresh()->key)->toBe('SHOP');
    // new project via the form: blank key is derived
    Livewire::actingAs($this->admin)->test(ProjectForm::class)->set('client_id', $this->client->id)->set('name', 'Zeta Works')->call('save')->assertHasNoErrors();
    expect(Project::where('name', 'Zeta Works')->value('key'))->toBe('ZET');
});

it('finds tasks by code, case-insensitively, with or without the prefix, and still by title', function () {
    tnTask($this->module, $this->admin, 'Login page');
    tnTask($this->module, $this->admin, 'Checkout');
    $titles = fn (string $q) => Task::search($q)->orderBy('id')->pluck('title')->all();
    expect($titles('NWS-2'))->toBe(['Checkout'])->and($titles('nws-2'))->toBe(['Checkout'])->and($titles('2'))->toBe(['Checkout'])
        ->and($titles('login'))->toBe(['Login page'])->and($titles('ZZZ-2'))->toBe([]);
    $cards = fn (string $q) => Livewire::actingAs($this->admin)->withQueryParams(['scope' => 'all', 'q' => $q])->test(Board::class, ['project' => $this->project]);
    $cards('nws-1')->assertSee('Login page')->assertDontSee('Checkout');
    $cards('2')->assertSee('Checkout')->assertDontSee('Login page');
});

it('shows the code on the board card, panel, task page, Modules tab, module page, sprint rows and portal', function () {
    $t = tnTask($this->module, $this->admin, 'Visible one', ['client_visible' => true, 'scope' => 'in']);
    $sprint = $this->project->sprints()->create(['name' => 'S1', 'status' => 'planned', 'starts_on' => today(), 'ends_on' => today()->addDays(6)]);
    $t->update(['sprint_id' => $sprint->id]);
    Livewire::actingAs($this->admin)->withQueryParams(['scope' => 'all'])->test(Board::class, ['project' => $this->project])->assertSeeHtml('data-test="task-code"')->assertSee('NWS-1');
    Livewire::actingAs($this->admin)->withQueryParams(['scope' => 'all', 'task' => $t->id])->test(Board::class, ['project' => $this->project])->assertSeeHtml('data-test="panel-code"');
    Livewire::actingAs($this->admin)->test(TaskShow::class, ['task' => $t])->assertSee('NWS-1')->assertSeeHtml('data-test="task-code"');
    Livewire::actingAs($this->admin)->test(ProjectShow::class, ['project' => $this->project, 'tab' => 'modules'])->assertSee('NWS-1');
    Livewire::actingAs($this->admin)->test(App\Livewire\Modules\Show::class, ['project' => $this->project, 'module' => $this->module])->assertSee('NWS-1');
    Livewire::actingAs($this->admin)->test(SprintPanel::class, ['project' => $this->project])->assertSee('NWS-1');
    $cu = User::factory()->create(['client_id' => $this->client->id]);
    $cu->assignRole('client');
    Livewire::actingAs($cu)->test(PortalView::class, ['project' => $this->project])->assertSee('NWS-1');
});

it('redirects /t/KEY-n for an authorised user and 404s otherwise', function () {
    $t = tnTask($this->module, $this->admin);
    $this->actingAs($this->admin)->get('/t/nws-1')->assertRedirect(route('tasks.show', $t));
    $this->actingAs($this->admin)->get('/t/NWS-99')->assertNotFound();
    $this->actingAs($this->admin)->get('/t/garbage')->assertNotFound();
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    $this->actingAs($dev)->get('/t/NWS-1')->assertNotFound(); // not a member
    ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $dev->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    $this->actingAs($dev)->get('/t/NWS-1')->assertRedirect(route('tasks.show', $t));
    auth()->logout();
    $this->get('/t/NWS-1')->assertRedirect(route('login'));
});

it('keeps billing amounts and the local twin after the rename', function () {
    $t = tnTask($this->module, $this->admin, 'Priced', ['budget_amount' => 200, 'board_column_id' => colId($this->module, 'Done')]);
    expect($t->fresh()->budget_amount_local)->not->toBeNull()->and($t->fresh()->exchange_rate)->not->toBeNull();
    app(BillableService::class)->syncCompletedWork($this->client);
    $line = app(BillableService::class)->pendingFor($this->client)->first();
    expect($line->amount_cents)->toBe(20000)->and($line->description)->toBe('NWS-1 Priced');
    $t->update(['budget_amount' => 250]);
    expect((float) $t->fresh()->budget_amount_local)->toBe(250 * (float) $t->fresh()->exchange_rate);
});

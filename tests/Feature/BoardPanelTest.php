<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\Board;
use App\Livewire\Tasks\Show;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $this->project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $this->module = Module::create(['project_id' => $this->project->id, 'name' => 'Alpha', 'position' => 1]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->dev = User::factory()->create();
    $this->dev->assignRole('developer');
    ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->dev->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    $this->task = pnTask($this->module, $this->admin, 'Build login');
});

function pnTask(Module $m, User $by, string $title, array $a = []): Task
{
    return Task::create(array_merge(['module_id' => $m->id, 'creator_id' => $by->id, 'title' => $title, 'description' => 'Panel body text', 'status' => TaskStatus::Published, 'priority' => 0, 'scope' => TaskScope::In, 'position' => 1], $a));
}

function pnBoard($t, array $q = [])
{
    return Livewire::actingAs($t->admin)->withQueryParams($q)->test(Board::class, ['project' => $t->project, 'scope' => 'all']);
}

it('opens the panel from ?task= and renders the task view inside it', function () {
    Subtask::create(['task_id' => $this->task->id, 'title' => 'Wire the form']);
    pnBoard($this, ['task' => $this->task->id, 'scope' => 'all'])
        ->assertSet('taskId', $this->task->id)
        ->assertSeeHtml('data-test="task-panel"')
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-labelledby="item-title"')
        ->assertSee('Panel body text')
        ->assertSee('Wire the form')
        ->assertSee('Timeline')
        ->assertSeeHtml('data-test="panel-full"')
        ->assertSeeHtml(route('tasks.show', $this->task));
});

it('does not open for nonexistent, foreign, or hidden tasks', function () {
    $other = Project::create(['client_id' => $this->project->client_id, 'name' => 'Other', 'status' => 'active']);
    $foreign = pnTask(Module::create(['project_id' => $other->id, 'name' => 'X', 'position' => 1]), $this->admin, 'Foreign secret');
    $draft = pnTask($this->module, $this->admin, 'Admin draft', ['status' => TaskStatus::Draft]);

    pnBoard($this, ['task' => 99999, 'scope' => 'all'])->assertSet('taskId', null)->assertDontSeeHtml('data-test="task-panel"');
    pnBoard($this, ['task' => $foreign->id, 'scope' => 'all'])->assertSet('taskId', null)->assertDontSee('Foreign secret', false)->assertDontSeeHtml('data-test="task-panel"');
    Livewire::actingAs($this->dev)->withQueryParams(['task' => $draft->id, 'scope' => 'all'])->test(Board::class, ['project' => $this->project])->assertSet('taskId', null)->assertDontSeeHtml('data-test="task-panel"');
    Livewire::actingAs($this->dev)->test(Board::class, ['project' => $this->project, 'scope' => 'all'])->call('openTask', $draft->id)->assertSet('taskId', null)->call('openTask', $foreign->id)->assertSet('taskId', null);
});

it('opens, swaps and closes through actions', function () {
    $second = pnTask($this->module, $this->admin, 'Second task', ['description' => 'Second body']);
    pnBoard($this)
        ->call('openTask', $this->task->id)->assertSet('taskId', $this->task->id)->assertSee('Panel body text')
        ->call('openTask', $second->id)->assertSet('taskId', $second->id)->assertSee('Second body')
        ->call('closeTask')->assertSet('taskId', null)->assertDispatched('task-panel-closed')->assertDontSeeHtml('data-test="task-panel"');
});

it('keeps the card click handler, pencil link and drag guard on the board', function () {
    pnBoard($this)
        ->assertSeeHtml('data-test="card-edit"')
        ->assertSeeHtml('openCard($event, '.$this->task->id.')')
        ->assertSeeHtml(route('tasks.show', $this->task));
});

it('panel edits announce themselves so the board card follows', function () {
    $col = $this->project->columns()->get()->last();
    Livewire::actingAs($this->admin)->test(Show::class, ['task' => $this->task, 'embedded' => true])
        ->call('moveColumn', $col->id)
        ->assertDispatched('task-panel-updated');
    expect($this->task->fresh()->board_column_id)->toBe($col->id);

    // the board re-reads its cards when told
    pnBoard($this)->call('openTask', $this->task->id)->dispatch('task-panel-updated')->assertSee('Build login');
});

it('the board tells an open panel when a card moved or was re-assigned', function () {
    $col = $this->project->columns()->get()->last();
    pnBoard($this)->call('openTask', $this->task->id)
        ->call('taskMoved', $this->task->id, $col->id, 1)->assertDispatched('board-task-changed')
        ->call('toggleTaskAssignee', $this->task->id, $this->dev->id)->assertDispatched('board-task-changed');
    expect($this->task->fresh()->assignees->pluck('id')->all())->toBe([$this->dev->id]);
});

it('panel actions use the task page authorisation', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('client');
    Livewire::actingAs($viewer)->test(Show::class, ['task' => $this->task, 'embedded' => true])->assertStatus(404);

    // a non-editor member can view but not edit
    $this->dev->syncRoles([]);
    Livewire::actingAs($this->dev)->test(Show::class, ['task' => $this->task, 'embedded' => true])->call('editTitle')->assertForbidden();
});

it('the task page still renders with its breadcrumb and header actions', function () {
    $this->actingAs($this->admin)->get(route('tasks.show', $this->task))->assertOk()->assertSee('data-test="copy-link"', false)->assertSee('data-test="new-task"', false);
    Livewire::actingAs($this->admin)->test(Show::class, ['task' => $this->task, 'embedded' => true])->assertDontSeeHtml('data-test="new-task"');
});

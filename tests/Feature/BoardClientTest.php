<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\Board;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();

    $client = Client::create(['name' => 'BcCo', 'currency' => 'USD']);
    $this->clientUser = User::factory()->create(['client_id' => $client->id]);
    $this->clientUser->assignRole('client');
    $this->project = Project::create(['client_id' => $client->id, 'name' => 'BcProject', 'status' => 'active']);
    $this->module = Module::create(['project_id' => $this->project->id, 'name' => 'BcMod', 'position' => 1, 'client_visible' => true]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('project_manager');
    ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->admin->id, 'role_on_project' => 'project_manager', 'access_scope' => AccessScope::All, 'active' => true]);
    [$this->colA, $this->colB, $this->colC] = $this->project->columns()->get()->take(3)->all();
    $this->colA->update(['client_movable' => true]);
    $this->colB->update(['client_movable' => true]);
});

function bclTask(array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id' => test()->module->id, 'creator_id' => test()->admin->id, 'title' => 'Bc Task',
        'status' => TaskStatus::Published, 'priority' => 1, 'estimate_hours' => 7.5, 'scope' => TaskScope::In,
        'position' => 1, 'client_visible' => true, 'board_column_id' => test()->colA->id,
    ], $attrs));
}

function bclBoard(User $user, array $query = [])
{
    return Livewire::withQueryParams($query)->actingAs($user)->test(Board::class, ['project' => test()->project, 'embedded' => true]);
}

it('lets only project editors toggle client_movable', function () {
    bclBoard($this->admin)->call('toggleClientMovable', $this->colC->id);
    expect($this->colC->fresh()->client_movable)->toBeTrue();

    bclBoard($this->clientUser)->call('toggleClientMovable', $this->colC->id)->assertForbidden();

    $dev = User::factory()->create();
    $dev->assignRole('developer');
    ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $dev->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    \App\Models\PermissionOverride::create(['user_id' => $dev->id, 'project_id' => $this->project->id, 'ability' => 'update', 'allowed' => false]);
    bclBoard($dev)->call('toggleClientMovable', $this->colC->id)->assertForbidden();
    expect($this->colC->fresh()->client_movable)->toBeTrue();
});

it('lets a client move a visible task between movable columns only', function () {
    $task = bclTask();

    bclBoard($this->clientUser)->assertSeeHtml('x-board-drag')->call('taskMoved', $task->id, $this->colB->id, 1)->assertOk();
    expect($task->fresh()->board_column_id)->toBe($this->colB->id);

    bclBoard($this->clientUser)->call('taskMoved', $task->id, $this->colC->id, 1)->assertForbidden();
    expect($task->fresh()->board_column_id)->toBe($this->colB->id);

    $locked = bclTask(['board_column_id' => $this->colC->id]);
    bclBoard($this->clientUser)->call('taskMoved', $locked->id, $this->colA->id, 1)->assertForbidden();
    expect($locked->fresh()->board_column_id)->toBe($this->colC->id);
});

it('refuses client moves of hidden tasks', function () {
    $internal = bclTask(['client_visible' => false]);
    $draft = bclTask(['status' => TaskStatus::Draft]);
    $request = bclTask(['request_status' => 'pending']);

    foreach ([$internal, $draft, $request] as $t) {
        bclBoard($this->clientUser)->call('taskMoved', $t->id, $this->colB->id, 1)->assertForbidden();
        expect($t->fresh()->board_column_id)->toBe($this->colA->id);
    }
});

it('lets a client open a visible card and comment, but not a hidden one', function () {
    $task = bclTask(['title' => 'Shared Card', 'description' => 'Card **details**', 'hourly_rate' => 98765]);
    $task->assignees()->attach(User::factory()->create(['name' => 'Secret Dev'])->id);
    $task->subtasks()->create(['title' => 'Sub One', 'position' => 1, 'estimate_hours' => 3]);
    $task->addComment($this->admin, 'Internal note');

    $c = bclBoard($this->clientUser)->call('openTask', $task->id)->assertSet('taskId', $task->id)
        ->assertSeeHtml('data-test="client-task-panel"')->assertSee('Shared Card')->assertSeeHtml('<strong>details</strong>')->assertSee('Sub One')
        ->assertDontSee('Internal note')->assertDontSee('Secret Dev')->assertDontSee('7.5h')->assertDontSee('98765')->assertDontSeeHtml('data-hours')
        ->assertDontSeeHtml('data-test="panel-full"')->assertDontSeeHtml('data-test="item-hours"');

    $c->set("taskBody.{$task->id}", 'Client says hi')->call('addComment', $task->id)->assertHasNoErrors()->assertSee('Client says hi');
    $root = $task->comments()->where('body', 'Client says hi')->first();
    expect($root->author_id)->toBe($this->clientUser->id);
    $c->set("replyBody.{$root->id}", 'And again')->call('reply', 'task', $task->id, $root->id)->assertHasNoErrors();
    expect($task->comments()->where('parent_id', $root->id)->count())->toBe(1);

    $hidden = bclTask(['client_visible' => false, 'title' => 'Hidden Card']);
    bclBoard($this->clientUser)->call('openTask', $hidden->id)->assertSet('taskId', null)->assertDontSee('Hidden Card');
    bclBoard($this->clientUser, ['task' => $hidden->id])->assertSet('taskId', null);
    bclBoard($this->clientUser)->set("taskBody.{$hidden->id}", 'x')->call('addComment', $hidden->id)->assertForbidden();
});

it('shows admins an eye icon on every card', function () {
    bclTask(['title' => 'Seen']);
    bclTask(['title' => 'Unseen', 'client_visible' => false]);

    $html = bclBoard($this->admin)->html();
    expect(substr_count($html, 'data-test="card-client"'))->toBe(2)
        ->and($html)->toContain('Hidden from the client')->toContain('Visible to the client');
    bclBoard($this->clientUser)->assertDontSeeHtml('data-test="card-client"');
});

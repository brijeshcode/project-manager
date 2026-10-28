<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\ListView;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

// ── helpers (local duplicates of TaskTest helpers to keep tests independent) ──

function makeListProject(): Project
{
    $client = Client::create(['name' => 'ListCo', 'currency' => 'USD']);
    return Project::create(['client_id' => $client->id, 'name' => 'ListProject', 'status' => 'active']);
}

function makeListModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'Mod', 'position' => 1]);
}

function makeListMember(Project $project, string $role = 'developer'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    ProjectMember::create([
        'project_id'      => $project->id,
        'user_id'         => $user->id,
        'role_on_project' => $role,
        'access_scope'    => AccessScope::All,
        'active'          => true,
    ]);
    return $user;
}

function makeListTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'List Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 0.0,
        'scope'          => TaskScope::In,
        'board_column'   => 'Backlog',
        'position'       => 1,
    ], $attrs));
}

// ── is_idea filter ────────────────────────────────────────────────────────────

it('stage=idea filter returns only ideas', function () {
    $project = makeListProject();
    $module  = makeListModule($project);
    $user    = makeListMember($project);

    makeListTask($module, $user, ['title' => 'Idea Task',    'is_idea' => true]);
    makeListTask($module, $user, ['title' => 'Regular Task', 'is_idea' => false]);

    $component = Livewire::actingAs($user)
        ->test(ListView::class, ['stage' => 'idea']);

    $tasks = $component->get('tasks');

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->title)->toBe('Idea Task');
});

it('stage=task filter returns only non-idea tasks', function () {
    $project = makeListProject();
    $module  = makeListModule($project);
    $user    = makeListMember($project);

    makeListTask($module, $user, ['title' => 'Idea Task',    'is_idea' => true]);
    makeListTask($module, $user, ['title' => 'Regular Task', 'is_idea' => false]);

    $component = Livewire::actingAs($user)
        ->test(ListView::class, ['stage' => 'task']);

    $tasks = $component->get('tasks');

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->title)->toBe('Regular Task');
});

it('no stage filter returns both ideas and tasks', function () {
    $project = makeListProject();
    $module  = makeListModule($project);
    $user    = makeListMember($project);

    makeListTask($module, $user, ['title' => 'Idea Task',    'is_idea' => true]);
    makeListTask($module, $user, ['title' => 'Regular Task', 'is_idea' => false]);

    $component = Livewire::actingAs($user)
        ->test(ListView::class);

    expect($component->get('tasks'))->toHaveCount(2);
});

// ── draft visibility guard ────────────────────────────────────────────────────

it('another user draft never appears in the list regardless of filters', function () {
    $project       = makeListProject();
    $module        = makeListModule($project);
    $draftOwner    = makeListMember($project);
    $viewer        = makeListMember($project);

    // Draft created by draftOwner — viewer must never see it
    makeListTask($module, $draftOwner, [
        'title'  => 'Secret Draft',
        'status' => TaskStatus::Draft,
    ]);

    // No filter
    $component = Livewire::actingAs($viewer)->test(ListView::class);
    expect($component->get('tasks'))->toHaveCount(0);

    // stage=task filter (drafts are not ideas — still must not appear)
    $component = Livewire::actingAs($viewer)->test(ListView::class, ['stage' => 'task']);
    expect($component->get('tasks'))->toHaveCount(0);

    // stage=idea filter
    $component = Livewire::actingAs($viewer)->test(ListView::class, ['stage' => 'idea']);
    expect($component->get('tasks'))->toHaveCount(0);
});

it('draft owner sees their own draft in the list', function () {
    $project    = makeListProject();
    $module     = makeListModule($project);
    $draftOwner = makeListMember($project);

    makeListTask($module, $draftOwner, [
        'title'  => 'My Draft',
        'status' => TaskStatus::Draft,
    ]);

    $component = Livewire::actingAs($draftOwner)->test(ListView::class);
    expect($component->get('tasks'))->toHaveCount(1);
});

// ── status filter ─────────────────────────────────────────────────────────────

it('status filter narrows results', function () {
    $project = makeListProject();
    $module  = makeListModule($project);
    $user    = makeListMember($project);

    makeListTask($module, $user, ['title' => 'Published', 'status' => TaskStatus::Published]);
    // user's own draft is visible to themselves
    makeListTask($module, $user, ['title' => 'My Draft', 'status' => TaskStatus::Draft]);

    $component = Livewire::actingAs($user)
        ->test(ListView::class, ['filterStatus' => 'published']);

    expect($component->get('tasks'))->toHaveCount(1)
        ->and($component->get('tasks')->first()->title)->toBe('Published');
});

// ── client_visible filter ─────────────────────────────────────────────────────

it('client_visible filter returns only client-visible tasks', function () {
    $project = makeListProject();
    $module  = makeListModule($project);
    $user    = makeListMember($project);

    makeListTask($module, $user, ['title' => 'Visible',   'client_visible' => true]);
    makeListTask($module, $user, ['title' => 'Hidden',    'client_visible' => false]);

    $component = Livewire::actingAs($user)
        ->test(ListView::class, ['filterClientVisible' => '1']);

    expect($component->get('tasks'))->toHaveCount(1)
        ->and($component->get('tasks')->first()->title)->toBe('Visible');
});

// ── label filter ──────────────────────────────────────────────────────────────

it('label filter returns only tasks with that label', function () {
    $project   = makeListProject();
    $module    = makeListModule($project);
    $user      = makeListMember($project);
    $bugLabel  = Label::create(['name' => 'Bug',     'color' => '#f00']);
    $featLabel = Label::create(['name' => 'Feature', 'color' => '#0f0']);

    $t1 = makeListTask($module, $user, ['title' => 'Bug Task']);
    $t1->labels()->attach($bugLabel->id);

    $t2 = makeListTask($module, $user, ['title' => 'Feature Task']);
    $t2->labels()->attach($featLabel->id);

    $component = Livewire::actingAs($user)
        ->test(ListView::class, ['filterLabel' => $bugLabel->id]);

    expect($component->get('tasks'))->toHaveCount(1)
        ->and($component->get('tasks')->first()->title)->toBe('Bug Task');
});

// ── assignee filter ───────────────────────────────────────────────────────────

it('assignee filter returns only tasks assigned to that user', function () {
    $project  = makeListProject();
    $module   = makeListModule($project);
    $user     = makeListMember($project);
    $assignee = makeListMember($project);

    $t1 = makeListTask($module, $user, ['title' => 'Assigned Task']);
    $t1->assignees()->attach($assignee->id);

    makeListTask($module, $user, ['title' => 'Unassigned Task']);

    $component = Livewire::actingAs($user)
        ->test(ListView::class, ['filterAssignee' => $assignee->id]);

    expect($component->get('tasks'))->toHaveCount(1)
        ->and($component->get('tasks')->first()->title)->toBe('Assigned Task');
});

// ── module filter ─────────────────────────────────────────────────────────────

it('module filter returns only tasks in that module', function () {
    $project = makeListProject();
    $module1 = makeListModule($project);
    $module2 = Module::create(['project_id' => $project->id, 'name' => 'Mod2', 'position' => 2]);
    $user    = makeListMember($project);

    makeListTask($module1, $user, ['title' => 'Mod1 Task']);
    makeListTask($module2, $user, ['title' => 'Mod2 Task']);

    $component = Livewire::actingAs($user)
        ->test(ListView::class, ['filterModule' => $module1->id]);

    expect($component->get('tasks'))->toHaveCount(1)
        ->and($component->get('tasks')->first()->title)->toBe('Mod1 Task');
});

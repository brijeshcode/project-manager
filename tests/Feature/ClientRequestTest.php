<?php

use App\Enums\AccessScope;
use App\Enums\TaskStatus;
use App\Livewire\Portal\ProjectView;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\Board;
use App\Models\Client;
use App\Models\Label;
use App\Models\Module;
use App\Models\PermissionOverride;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Services\ModuleBudget;
use App\Services\ProgressService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $this->project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $this->module = Module::create(['project_id' => $this->project->id, 'name' => 'Shared', 'position' => 1, 'client_visible' => true]);
    $this->clientUser = User::factory()->create(['client_id' => $client->id]);
    $this->clientUser->assignRole('client');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

function crqPortal()
{
    return Livewire::actingAs(test()->clientUser)->test(ProjectView::class, ['project' => test()->project]);
}

function crqTask(array $form)
{
    return crqPortal()->call('openTaskRequest')->set('taskForm', $form + ['module_id' => test()->module->id, 'title' => 'Req', 'description' => '', 'priority' => 0, 'task_type_id' => null, 'labels' => [], 'deadline' => ''])->call('saveTaskRequest');
}

function crqAdmin(?User $as = null)
{
    return Livewire::actingAs($as ?? test()->admin)->test(Show::class, ['project' => test()->project])->set('tab', 'modules');
}

it('client requests a module: client-visible and pending', function () {
    crqPortal()->call('openModuleRequest')
        ->set('moduleForm', ['name' => 'Blog', 'description' => 'Posts', 'deadline' => '2027-01-31', 'priority' => 2])
        ->call('saveModuleRequest')->assertHasNoErrors()->assertSee('Awaiting approval');

    $m = Module::where('name', 'Blog')->firstOrFail();
    expect($m->client_visible)->toBeTrue()->and($m->request_status)->toBe('pending')
        ->and($m->priority)->toBe(2)->and($m->deadline->toDateString())->toBe('2027-01-31');
});

it('client requests a task with allowed fields only', function () {
    $label = Label::create(['name' => 'UI', 'color' => 'blue']);
    crqTask(['title' => 'Dark mode', 'description' => 'Please', 'priority' => 2, 'labels' => [$label->id], 'deadline' => '2027-02-01',
        'budget_amount' => 999, 'hourly_rate' => 50, 'client_visible' => false, 'is_idea' => true, 'status' => 'draft', 'scope' => 'out'])
        ->assertHasNoErrors();

    $t = Task::where('title', 'Dark mode')->firstOrFail();
    expect($t->request_status)->toBe('pending')->and($t->client_visible)->toBeTrue()->and($t->status)->toBe(TaskStatus::Published)
        ->and($t->is_idea)->toBeFalse()->and($t->scope->value)->toBe('in')->and($t->budget_amount)->toBeNull()->and($t->hourly_rate)->toBeNull()
        ->and($t->priority)->toBe(2)->and($t->labels->pluck('id')->all())->toBe([$label->id])->and($t->assignees)->toBeEmpty()
        ->and($t->board_column_id)->toBe($this->project->columns()->value('id'));
});

it('client cannot add a task to a hidden, pending or foreign module', function () {
    $hidden = Module::create(['project_id' => $this->project->id, 'name' => 'Hidden', 'position' => 2]);
    $pending = Module::create(['project_id' => $this->project->id, 'name' => 'P', 'position' => 3, 'client_visible' => true, 'request_status' => 'pending']);
    $other = Project::create(['client_id' => Client::create(['name' => 'O', 'currency' => 'USD'])->id, 'name' => 'Other', 'status' => 'active']);
    $foreign = Module::create(['project_id' => $other->id, 'name' => 'F', 'position' => 1, 'client_visible' => true]);

    foreach ([$hidden, $pending, $foreign] as $m) {
        crqTask(['module_id' => $m->id])->assertHasErrors('taskForm.module_id');
    }
    expect(Task::count())->toBe(0);
});

it('admin approves and rejects requests; reject needs a reason', function () {
    crqTask(['title' => 'A']);
    crqTask(['title' => 'B']);
    [$a, $b] = [Task::where('title', 'A')->first(), Task::where('title', 'B')->first()];

    crqAdmin()->assertSee('Client request')->call('approveRequest', 'task', $a->id)
        ->call('rejectRequest', 'task', $b->id)->assertHasErrors('rejectNote.task.'.$b->id)
        ->set("rejectNote.task.{$b->id}", str_repeat('x', 1001))->call('rejectRequest', 'task', $b->id)->assertHasErrors('rejectNote.task.'.$b->id)
        ->set("rejectNote.task.{$b->id}", 'Out of scope')->call('rejectRequest', 'task', $b->id)->assertHasNoErrors();

    expect($a->fresh()->request_status)->toBeNull()
        ->and($b->fresh()->request_status)->toBe('rejected')->and($b->fresh()->request_note)->toBe('Out of scope');
    crqPortal()->assertSee('Rejected: Out of scope');
});

it('shows the waiting count on the admin details tab', function () {
    crqTask(['title' => 'A']);
    Module::create(['project_id' => $this->project->id, 'name' => 'P', 'position' => 2, 'client_visible' => true, 'request_status' => 'pending']);

    Livewire::actingAs($this->admin)->test(Show::class, ['project' => $this->project])->assertSee('2 client requests waiting for approval');
});

it('keeps pending items out of the board, counted, budgeted and progress', function () {
    $done = $this->project->columns()->where('stage', 'done')->value('id');
    $ok = Task::create(['module_id' => $this->module->id, 'creator_id' => $this->admin->id, 'title' => 'Ok', 'status' => 'published', 'scope' => 'in', 'budget_amount' => 100]);
    $req = Task::create(['module_id' => $this->module->id, 'creator_id' => $this->clientUser->id, 'title' => 'Req', 'status' => 'published', 'scope' => 'in', 'budget_amount' => 50, 'request_status' => 'pending', 'board_column_id' => $done]);
    $pm = Module::create(['project_id' => $this->project->id, 'name' => 'PM', 'position' => 2, 'request_status' => 'pending']);
    $inPending = Task::create(['module_id' => $pm->id, 'creator_id' => $this->admin->id, 'title' => 'InPending', 'status' => 'published', 'scope' => 'in', 'board_column_id' => $done]);

    expect(Task::counted()->pluck('id')->all())->toBe([$ok->id])
        ->and(Task::budgeted()->pluck('id')->all())->toBe([$ok->id])
        ->and((new ModuleBudget($this->module))->used())->toBe(10000)
        ->and(app(ProgressService::class)->forModule($this->module))->toBe(0.0)
        ->and(app(ProgressService::class)->forProject($this->project))->toBe(0.0);

    $ids = Livewire::actingAs($this->admin)->test(Board::class, ['project' => $this->project])->get('columns')->flatten()->pluck('id');
    expect($ids->all())->toBe([$ok->id]);

    $inPending->update(['budget_amount' => 70]);
    app(\App\Services\BillableService::class)->syncCompletedWork($this->project->client);
    expect(\App\Models\BillableLine::count())->toBe(0); // $req and $inPending are done but unapproved
});

it('client edits a rejected task back to pending; only their own', function () {
    crqTask(['title' => 'A']);
    $t = Task::where('title', 'A')->first();
    $t->update(['request_status' => 'rejected', 'request_note' => 'No']);

    crqPortal()->call('openTaskRequest', $t->id)->assertSet('taskForm.title', 'A')
        ->set('taskForm.title', 'A2')->call('saveTaskRequest')->assertHasNoErrors();
    expect($t->fresh())->title->toBe('A2')->request_status->toBe('pending')->request_note->toBeNull();

    $t->update(['request_status' => null]);
    expect(fn () => crqPortal()->call('openTaskRequest', $t->id))->toThrow(ModelNotFoundException::class);

    $other = User::factory()->create(['client_id' => $this->clientUser->client_id]);
    $other->assignRole('client');
    $t->update(['request_status' => 'pending']);
    expect(fn () => Livewire::actingAs($other)->test(ProjectView::class, ['project' => $this->project])->call('deleteTaskRequest', $t->id))->toThrow(ModelNotFoundException::class);
    crqPortal()->call('deleteTaskRequest', $t->id);
    expect(Task::find($t->id))->toBeNull();
});

it('a member who cannot update the project cannot approve', function () {
    crqTask(['title' => 'A']);
    $t = Task::where('title', 'A')->first();
    $member = User::factory()->create();
    $member->assignRole('developer');
    ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $member->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    PermissionOverride::create(['user_id' => $member->id, 'project_id' => $this->project->id, 'ability' => 'update', 'allowed' => false]);

    crqAdmin($member)->assertDontSee('data-test="approve-request"', false)->call('approveRequest', 'task', $t->id)->assertForbidden();
    expect($t->fresh()->request_status)->toBe('pending');
});

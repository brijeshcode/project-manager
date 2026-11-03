<?php

use App\Livewire\Projects\Form;
use App\Livewire\Tasks\Board;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Support\BoardTemplates;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
});

function bcolProject(string $template = 'software'): Project
{
    $client = Client::firstOrCreate(['name' => 'ColCo'], ['currency' => 'USD']);

    return Project::create(['client_id' => $client->id, 'name' => 'P'.$template.uniqid(), 'status' => 'active', 'template' => $template]);
}

function bcolPm(): User
{
    $u = User::factory()->create();
    $u->assignRole('super_admin');

    return $u;
}

function bcolModule(Project $p): Module
{
    return Module::create(['project_id' => $p->id, 'name' => 'M'.uniqid(), 'position' => 1]);
}

function bcolTask(Module $m, string $col, array $extra = []): Task
{
    return Task::create($extra + ['module_id' => $m->id, 'creator_id' => bcolPm()->id, 'title' => 'T'.uniqid(), 'status' => 'published', 'board_column_id' => colId($m, $col)]);
}

it('copies the software template onto a new project by default', function () {
    $client  = Client::create(['name' => 'DefCo', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Def', 'status' => 'active']);

    expect($project->columns->map(fn ($c) => [$c->name, $c->stage])->all())->toBe(BoardTemplates::ALL['software'])
        ->and($project->columns)->toHaveCount(8);
});

it('copies the blank template when chosen', function () {
    expect(bcolProject('blank')->columns->map(fn ($c) => [$c->name, $c->stage])->all())
        ->toBe([['Backlog', 'not_started'], ['Done', 'done']]);
});

it('project form picks the template on create', function () {
    $client = Client::create(['name' => 'FormCo', 'currency' => 'USD']);

    Livewire::actingAs(bcolPm())->test(Form::class)
        ->set('client_id', $client->id)->set('name', 'Tpl')->set('template', 'blank')->call('save')->assertHasNoErrors();

    expect(Project::where('name', 'Tpl')->first()->columns)->toHaveCount(2);
});

it('first and last columns are locked from deletion', function () {
    $project = bcolProject();
    $first   = $project->columns->first();
    $last    = $project->columns->last();

    Livewire::actingAs(bcolPm())->test(Board::class, ['project' => $project])
        ->set("deleteTarget.{$first->id}", $last->id)->call('deleteColumn', $first->id)
        ->set("deleteTarget.{$last->id}", $first->id)->call('deleteColumn', $last->id);

    expect($project->columns()->count())->toBe(8);
});

it('deleting a column needs a target and moves its tasks', function () {
    $project = bcolProject();
    $task    = bcolTask(bcolModule($project), 'Blocked');
    $blocked = colId($project, 'Blocked');

    $c = Livewire::actingAs(bcolPm())->test(Board::class, ['project' => $project])
        ->call('deleteColumn', $blocked)
        ->assertHasErrors(["deleteTarget.$blocked"]);
    expect($project->columns()->whereKey($blocked)->exists())->toBeTrue();

    $c->set("deleteTarget.$blocked", colId($project, 'Testing'))->call('deleteColumn', $blocked);

    expect($project->columns()->whereKey($blocked)->exists())->toBeFalse()
        ->and($task->fresh()->board_column_id)->toBe(colId($project, 'Testing'));
});

it('adds columns before the last, renames, changes stage and reorders', function () {
    $project = bcolProject();

    $c = Livewire::actingAs(bcolPm())->test(Board::class, ['project' => $project])
        ->set('newColumnName', 'QA')->set('newColumnStage', 'review')->call('addColumn');
    expect($project->columns()->pluck('name')->all())
        ->toBe(['Backlog', 'Sprint plan', 'In progress', 'Blocked', 'Testing', 'Review', 'Done', 'QA', 'Delivered']);

    $qa = $project->columns()->where('name', 'QA')->first();
    $c->call('renameColumn', $qa->id, 'Quality')->call('setStage', $qa->id, 'active')->call('moveColumn', $qa->id, -1);
    expect($qa->fresh()->name)->toBe('Quality')->and($qa->fresh()->stage)->toBe('active')
        ->and($project->columns()->pluck('name')->all()[6])->toBe('Quality');

    // the locked first column neither changes stage nor moves
    $first = $project->columns->first();
    $c->call('setStage', $first->id, 'done')->call('moveColumn', $first->id, 1);
    expect($first->fresh()->stage)->toBe('not_started')->and($project->columns()->first()->id)->toBe($first->id);
});

it('shows module label, subtask count, filters by module and groups by module', function () {
    $project = bcolProject();
    $m1 = Module::create(['project_id' => $project->id, 'name' => 'Alpha', 'position' => 1]);
    $m2 = Module::create(['project_id' => $project->id, 'name' => 'Beta', 'position' => 2]);
    $t1 = bcolTask($m1, 'Backlog', ['title' => 'One']);
    bcolTask($m2, 'Backlog', ['title' => 'Two']);
    foreach ([true, false] as $i => $done) {
        $s = Subtask::create(['task_id' => $t1->id, 'title' => "s$i"]);
        ChecklistItem::create(['checklistable_type' => Subtask::class, 'checklistable_id' => $s->id, 'title' => 'x', 'is_done' => $done, 'position' => 1]);
    }

    Livewire::actingAs(bcolPm())->test(Board::class, ['project' => $project])
        ->assertSee('Alpha')->assertSee('1/2 done')->assertSee('One')->assertSee('Two')
        ->set('filterModule', $m2->id)->assertDontSee('One')->assertSee('Two')
        ->set('filterModule', null)->set('grouped', true)->assertSee('Alpha')->assertSee('Beta')->assertSee('One');
});

it('derives module status from stages on the software template', function (array $cols, string $expected) {
    $p = bcolProject('software');
    $m = bcolModule($p);
    foreach ($cols as $c) {
        bcolTask($m, $c);
    }

    expect($m->derivedStatus())->toBe($expected);
})->with([
    'no tasks'                  => [[], 'pending'],
    'all not_started'           => [['Backlog', 'Sprint plan'], 'pending'],
    'not_started + active'      => [['Backlog', 'In progress'], 'working'],
    'active + done'             => [['Blocked', 'Done'], 'working'],
    'review + done'             => [['Testing', 'Review', 'Done'], 'in_review'],
    'review + not_started'      => [['Testing', 'Backlog'], 'working'],
    'all done (both done cols)' => [['Done', 'Delivered'], 'completed'],
]);

it('derives module status from stages on the blank template', function (array $cols, string $expected) {
    $p = bcolProject('blank');
    $m = bcolModule($p);
    foreach ($cols as $c) {
        bcolTask($m, $c);
    }

    expect($m->derivedStatus())->toBe($expected);
})->with([
    'no tasks'    => [[], 'pending'],
    'all backlog' => [['Backlog', 'Backlog'], 'pending'],
    'mixed'       => [['Backlog', 'Done'], 'working'],
    'all done'    => [['Done', 'Done'], 'completed'],
]);

it('progress is by count, hours-weighted when estimates exist, 0 when empty', function () {
    $p = bcolProject('blank');
    $m = bcolModule($p);
    $svc = app(\App\Services\ProgressService::class);
    expect($svc->forModule($m))->toBe(0.0);

    $tasks = collect(['Backlog', 'Done', 'Done', 'Backlog'])->map(fn ($c) => bcolTask($m, $c));
    expect($svc->forModule($m))->toBe(50.0);

    foreach ([6, 2, 2, 0] as $i => $h) {
        $tasks[$i]->update(['estimate_hours' => $h]);
    }
    expect(round($svc->forModule($m), 2))->toBe(40.0);   // 4 of 10 hours done
});

it('ignores ideas, drafts and out-of-scope tasks in status and progress', function () {
    $p = bcolProject('blank');
    $m = bcolModule($p);
    bcolTask($m, 'Done');
    bcolTask($m, 'Backlog', ['is_idea' => true]);
    bcolTask($m, 'Backlog', ['status' => 'draft']);
    bcolTask($m, 'Backlog', ['scope' => 'out']);

    expect($m->derivedStatus())->toBe('completed')->and(app(\App\Services\ProgressService::class)->forModule($m))->toBe(100.0);
});

it('new tasks land in the first column and tasks cannot move to another project\'s column', function () {
    $p = bcolProject();
    $task = Task::create(['module_id' => bcolModule($p)->id, 'creator_id' => bcolPm()->id, 'title' => 'x', 'status' => 'published']);
    expect($task->column->name)->toBe('Backlog');

    $task->moveTo(colId(bcolProject(), 'Done'), 3);
    expect($task->fresh()->board_column_id)->toBe(colId($p, 'Backlog'));
});

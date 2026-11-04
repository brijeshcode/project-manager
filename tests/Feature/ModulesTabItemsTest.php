<?php

use App\Livewire\Projects\Show;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mtSetup(): array
{
    $project = Project::create(['client_id' => Client::create(['name' => 'Acme', 'currency' => 'USD'])->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $mk = function (string $role) use ($project) {
        $u = User::factory()->create();
        $u->assignRole($role);
        $project->members()->attach($u->id, ['role_on_project' => $role, 'access_scope' => 'all', 'active' => true]);

        return $u;
    };
    $module = Module::create(['project_id' => $project->id, 'name' => 'M']);
    $task = Task::create(['module_id' => $module->id, 'creator_id' => $mk('project_manager')->id, 'title' => 'T', 'status' => 'published', 'scope' => 'in']);

    return [$project, $task, $mk('project_manager'), $mk('developer')];
}

it('renders the add-subtask link collapsed, with the form hidden until revealed', function () {
    [$project, $task, $pm] = mtSetup();

    Livewire::actingAs($pm)->test(Show::class, ['project' => $project])->set('tab', 'modules')
        ->assertSeeHtml('data-test="add-subtask-link"')
        ->assertSeeHtml('x-data="{ adding: false }"')
        ->assertSeeHtml('data-test="add-subtask-form"');
    // form is x-show="adding" and display:none server-side
    $html = Livewire::actingAs($pm)->test(Show::class, ['project' => $project])->set('tab', 'modules')->html();
    expect($html)->toMatch('/<form x-show="adding" style="display:none"[^>]*data-test="add-subtask-form"/');
});

it('adds a subtask from the modules tab and keeps the form closing on submit', function () {
    [$project, $task, $pm] = mtSetup();

    Livewire::actingAs($pm)->test(Show::class, ['project' => $project])->set('tab', 'modules')
        ->set("newSubtaskTitle.{$task->id}", 'Sub')
        ->call('addSubtask', $task->id)
        ->assertSet("newSubtaskTitle.{$task->id}", '');
    expect($task->subtasks()->pluck('title')->all())->toBe(['Sub']);
});

it('persists checklist and subtask drag reorder', function () {
    [$project, $task, $pm] = mtSetup();
    $items = collect(['a', 'b', 'c'])->map(fn ($t, $i) => ChecklistItem::create(['checklistable_type' => Task::class, 'checklistable_id' => $task->id, 'title' => $t, 'is_done' => false, 'position' => $i + 1]));
    $subs = collect(['x', 'y', 'z'])->map(fn ($t) => Subtask::create(['task_id' => $task->id, 'title' => $t]));

    Livewire::actingAs($pm)->test(Show::class, ['project' => $project])->set('tab', 'modules')
        ->assertSeeHtml('data-sort-method="reorderChecklist"')
        ->assertSeeHtml('data-sort-method="reorderSubtasksOf"')
        ->call('reorderChecklist', $task->id, [$items[2]->id, $items[0]->id, $items[1]->id])
        ->call('reorderSubtasksOf', $task->id, [$subs[1]->id, $subs[2]->id, $subs[0]->id]);

    expect($task->checklistItems()->pluck('title')->all())->toBe(['c', 'a', 'b'])
        ->and($task->subtasks()->pluck('title')->all())->toBe(['y', 'z', 'x']);
});

it('forbids reordering for a non-member and ignores foreign tasks', function () {
    [$project, $task, $pm] = mtSetup();
    $item = ChecklistItem::create(['checklistable_type' => Task::class, 'checklistable_id' => $task->id, 'title' => 'a', 'is_done' => false, 'position' => 1]);
    $outsider = User::factory()->create();
    $outsider->assignRole('developer');

    $mount = fn () => Livewire::actingAs($pm)->test(Show::class, ['project' => $project])->set('tab', 'modules');
    $a = $mount();
    $b = $mount();
    $this->actingAs($outsider);
    $a->call('reorderChecklist', $task->id, [$item->id])->assertForbidden();
    $b->call('reorderSubtasksOf', $task->id, [1])->assertForbidden();

    $other = mtSetup()[1];
    expect(fn () => $mount()->call('reorderChecklist', $other->id, [1]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

<?php

use App\Enums\AccessScope;
use App\Livewire\Modules\Show as ModuleShow;
use App\Livewire\Tasks\Show;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
});

function amSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $module = Module::create(['project_id' => $project->id, 'name' => 'Checkout', 'position' => 1]);
    $mk = function (string $role, array $a = []) {
        $u = User::factory()->create($a);
        $u->assignRole($role);

        return $u;
    };
    $admin = $mk('super_admin');
    $dev = $mk('developer');
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $dev->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    $client_user = $mk('client', ['client_id' => $client->id]);
    $task = Task::create(['module_id' => $module->id, 'creator_id' => $admin->id, 'title' => 'T', 'status' => 'published', 'client_visible' => true]);

    return compact('project', 'module', 'admin', 'dev', 'client_user', 'task');
}

it('logs task amount, hours, rate and mode changes', function () {
    $s = amSetup();
    $this->actingAs($s['admin']);
    $s['task']->update(['budget_amount' => 500]);
    $s['task']->update(['billing_mode' => 'hourly', 'hourly_rate' => 40, 'billable_hours' => 3]);

    $rows = Activity::where('subject_type', $s['task']->getMorphClass())->where('subject_id', $s['task']->id)->where('event', 'changed')->get();
    expect($rows->pluck('field')->all())->toContain('budget_amount', 'billing_mode', 'hourly_rate', 'billable_hours')
        // the hourly amount is derived from hours x rate: no row of its own
        ->and($rows->where('field', 'budget_amount')->count())->toBe(1)
        ->and($rows->firstWhere('field', 'budget_amount')->new)->toBe('500');
});

it('logs module budget field changes besides the budget-change rows', function () {
    $s = amSetup();
    $s['module']->updateBudget(['budget_amount' => '1000'], $s['admin'], '');
    $s['module']->fresh()->updateBudget(['budget_amount' => '1200'], $s['admin'], 'scope grew');

    $rows = $s['module']->activities()->where('field', 'budget_amount')->get();
    expect($rows)->toHaveCount(2)->and($rows->first()->new)->toBe('1200.00');
    expect($s['module']->budgetChanges()->count())->toBe(2);
});

it('shows amount rows to admins but hides them from developers on the timeline', function () {
    $s = amSetup();
    $this->actingAs($s['admin']);
    $s['task']->update(['budget_amount' => 777, 'title' => 'Renamed']);

    Livewire::actingAs($s['admin'])->test(Show::class, ['task' => $s['task']->fresh()])
        ->assertSee('budget amount')->assertSee('777');
    Livewire::actingAs($s['dev'])->test(Show::class, ['task' => $s['task']->fresh()])
        ->assertDontSee('budget amount')->assertDontSee('777')->assertSee('Renamed');
});

it('hides module amount rows from developers and keeps clients out of the activity entirely', function () {
    $s = amSetup();
    $s['module']->updateBudget(['budget_amount' => '4321'], $s['admin'], '');
    $s['module']->update(['name' => 'Checkout v2']);

    Livewire::actingAs($s['admin'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']->fresh()])->assertSee('budget amount')->assertSee('4321.00');
    Livewire::actingAs($s['dev'])->test(ModuleShow::class, ['project' => $s['project'], 'module' => $s['module']->fresh()])
        ->assertDontSee('budget amount')->assertDontSee('4321')->assertDontSee('4,321');

    $this->actingAs($s['client_user'])->get(route('tasks.show', $s['task']))->assertNotFound(); // clients never reach the staff page, so never its activity
});

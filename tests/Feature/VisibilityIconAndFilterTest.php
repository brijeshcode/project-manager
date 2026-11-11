<?php

use App\Livewire\Portal\ProjectView;
use App\Livewire\Projects\Economics;
use App\Livewire\Projects\Show;
use App\Livewire\Sprints\Panel;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $this->project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'status' => 'active']);
    $this->module = Module::create(['project_id' => $this->project->id, 'name' => 'Open Module', 'position' => 1, 'client_visible' => true, 'budget_amount' => '100']);
    $this->clientUser = User::factory()->create(['client_id' => $client->id])->assignRole('client');
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->task = Task::create(['module_id' => $this->module->id, 'creator_id' => $this->admin->id, 'title' => 'Eye Task', 'status' => 'published', 'client_visible' => true, 'budget_amount' => '40']);
});

it('admin toggles task visibility from the Modules list', function () {
    Livewire::actingAs($this->admin)->test(Show::class, ['project' => $this->project])->set('tab', 'modules')
        ->assertSeeHtml('toggleTaskVisibility('.$this->task->id.')')
        ->call('toggleTaskVisibility', $this->task->id)
        ->assertDispatched('toast');
    expect($this->task->fresh()->client_visible)->toBeFalse();
});

it('admin toggles task visibility from the Sprint panel', function () {
    Livewire::actingAs($this->admin)->test(Panel::class, ['project' => $this->project])
        ->assertSeeHtml('toggleTaskVisibility('.$this->task->id.')')
        ->call('toggleTaskVisibility', $this->task->id);
    expect($this->task->fresh()->client_visible)->toBeFalse();
});

it('rejects a task from another project', function () {
    $other = Project::create(['client_id' => $this->project->client_id, 'name' => 'Other', 'status' => 'active']);
    $om = Module::create(['project_id' => $other->id, 'name' => 'X', 'position' => 1]);
    $ot = Task::create(['module_id' => $om->id, 'creator_id' => $this->admin->id, 'title' => 'Foreign', 'status' => 'published']);
    Livewire::actingAs($this->admin)->test(Show::class, ['project' => $this->project])->call('toggleTaskVisibility', $ot->id);
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('client cannot toggle task visibility and sees no eye icon', function () {
    Livewire::actingAs($this->clientUser)->test(Panel::class, ['project' => $this->project])
        ->assertDontSeeHtml('data-test="task-visibility"')
        ->call('toggleTaskVisibility', $this->task->id)
        ->assertForbidden();
    Livewire::actingAs($this->clientUser)->test(ProjectView::class, ['project' => $this->project])
        ->assertSee('Eye Task')
        ->assertDontSeeHtml('data-test="task-visibility"');
    expect($this->task->fresh()->client_visible)->toBeTrue();
});

it('client modules tab has the filter bar and a Completed section', function () {
    Module::create(['project_id' => $this->project->id, 'name' => 'Finished Module', 'position' => 2, 'client_visible' => true, 'status_override' => 'completed']);
    Module::create(['project_id' => $this->project->id, 'name' => 'Asked Module', 'position' => 3, 'client_visible' => true, 'request_status' => 'pending']);

    $c = Livewire::actingAs($this->clientUser)->test(ProjectView::class, ['project' => $this->project])
        ->assertSeeHtml('data-test="module-filters"')
        ->assertDontSeeHtml('data-test="filter-user"')
        ->assertSeeHtml('data-test="completed-section"')
        ->assertSeeInOrder(['Open Module', 'Asked Module', 'completed-section', 'Finished Module']);

    $c->set('filter', 'completed')
        ->assertSee('Finished Module')
        ->assertDontSee('Open Module')
        ->assertDontSeeHtml('data-test="completed-section"');
});

it('request modules are left out of counts and budget', function () {
    $req = Module::create(['project_id' => $this->project->id, 'name' => 'Asked Module', 'position' => 2, 'client_visible' => true, 'request_status' => 'pending', 'budget_amount' => '999']);

    $one = ['data-test="counts-modules"', '<div class="text-xl font-semibold">1</div>', 'data-test="counts-tasks"'];
    Livewire::actingAs($this->clientUser)->test(ProjectView::class, ['project' => $this->project])->set('tab', 'details')
        ->assertSeeHtmlInOrder($one);
    Livewire::actingAs($this->clientUser)->test(ProjectView::class, ['project' => $this->project])->set('tab', 'budget')
        ->assertSee('Open Module')->assertDontSee('Asked Module');
    Livewire::actingAs($this->admin)->test(Show::class, ['project' => $this->project])->set('tab', 'details')
        ->assertSeeHtmlInOrder($one);

    $e = Livewire::actingAs($this->admin)->test(Economics::class, ['project' => $this->project, 'embedded' => true]);
    expect($e->instance()->budgetModules()->pluck('id')->all())->toBe([$this->module->id]);
    $e->assertDontSee('Asked Module');
});

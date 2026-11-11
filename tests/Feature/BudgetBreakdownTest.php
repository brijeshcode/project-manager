<?php

use App\Livewire\Portal\ProjectView;
use App\Livewire\Projects\Economics;
use App\Livewire\Projects\Show;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'BbCo', 'currency' => 'USD', 'billing_mode' => 'fixed']);
    $this->cu = User::factory()->create(['client_id' => $this->client->id])->assignRole('client');
    $this->p = Project::create(['client_id' => $this->client->id, 'name' => 'Bb Project', 'key' => 'BBP', 'status' => 'active', 'aim' => 'Ship the shop', 'description' => 'Online store rebuild']);
    $this->done = $this->p->columns->firstWhere('stage', 'done')->id;
    $this->fixed = Module::create(['project_id' => $this->p->id, 'name' => 'Checkout', 'position' => 1, 'client_visible' => true, 'budget_amount' => '1234']);
    $this->hourly = Module::create(['project_id' => $this->p->id, 'name' => 'Support', 'position' => 2, 'client_visible' => true, 'billing_mode' => 'hourly', 'budget_hours' => '40', 'hourly_rate' => '50']);
    $this->hidden = Module::create(['project_id' => $this->p->id, 'name' => 'Secret Module', 'position' => 3]);
});

function bbTask(Module $m, User $by, array $a = []): Task
{
    return Task::create($a + ['module_id' => $m->id, 'creator_id' => $by->id, 'title' => 'T', 'status' => 'published', 'client_visible' => true, 'estimate_hours' => 3]);
}

it('splits completed and pending work and shows module budgets', function () {
    bbTask($this->fixed, $this->admin, ['title' => 'Done Task', 'budget_amount' => '300', 'board_column_id' => $this->done]);
    bbTask($this->fixed, $this->admin, ['title' => 'Open Task', 'budget_amount' => '200']);
    bbTask($this->fixed, $this->admin, ['title' => 'No Amount']);
    bbTask($this->fixed, $this->admin, ['title' => 'Idea Task', 'budget_amount' => '9000', 'is_idea' => true]);

    Livewire::actingAs($this->admin)->test(Economics::class, ['project' => $this->p, 'embedded' => true])
        ->assertSeeInOrder(['Completed work', '$300.00', 'Pending work', '$200.00', 'Total', '$500.00'])
        ->assertSeeInOrder(['Checkout', '$1,234.00', 'Done Task', 'Open Task', 'No Amount', '—', 'Tasks total', '$500.00'])
        ->assertDontSee('Idea Task')
        ->assertSee('Secret Module')
        ->assertSee('Hours logged');
});

it('shows an hourly task value as billed hours x rate', function () {
    $t = bbTask($this->hourly, $this->admin, ['title' => 'Hourly Task', 'billable_hours' => '3']);
    $t->fresh()->recalculateValue();

    Livewire::actingAs($this->admin)->test(Economics::class, ['project' => $this->p, 'embedded' => true])
        ->assertSeeInOrder(['Support', '$2,000.00', 'Hourly Task', '$150.00']);
});

it('client budget tab shows only visible items and no hours or rates', function () {
    bbTask($this->fixed, $this->admin, ['title' => 'Visible Task', 'budget_amount' => '300', 'board_column_id' => $this->done]);
    bbTask($this->fixed, $this->admin, ['title' => 'Internal Task', 'budget_amount' => '4444', 'client_visible' => false]);
    bbTask($this->fixed, $this->admin, ['title' => 'Draft Task', 'budget_amount' => '5555', 'status' => 'draft']);
    bbTask($this->hidden, $this->admin, ['title' => 'Hidden Task', 'budget_amount' => '6666']);
    bbTask($this->hourly, $this->admin, ['title' => 'Hourly Task', 'billable_hours' => '3'])->fresh()->recalculateValue();

    Livewire::actingAs($this->cu)->test(ProjectView::class, ['project' => $this->p])->set('tab', 'budget')
        ->assertSeeInOrder(['Completed work', '$300.00', 'Pending work', '$150.00', 'Total', '$450.00'])
        ->assertSee('Visible Task')->assertSee('Hourly Task')->assertSee('$1,234.00')
        ->assertDontSee('Internal Task')->assertDontSee('4,444')->assertDontSee('Draft Task')->assertDontSee('5,555')
        ->assertDontSee('Secret Module')->assertDontSee('Hidden Task')->assertDontSee('6,666')
        ->assertDontSee('Hours logged')->assertDontSee('Cost of logged')->assertDontSee('Hourly rate')->assertDontSee('₹')->assertDontSee('INR');
});

it('hides the economics tab and component from non-money users', function () {
    $pm = User::factory()->create()->assignRole('project_manager');
    \App\Models\ProjectMember::create(['project_id' => $this->p->id, 'user_id' => $pm->id, 'role_on_project' => 'project_manager', 'access_scope' => \App\Enums\AccessScope::All, 'active' => true]);

    Livewire::actingAs($pm)->test(Show::class, ['project' => $this->p])->assertDontSee('Economics')->assertDontSee('Completed work');
    Livewire::actingAs($pm)->test(Economics::class, ['project' => $this->p])->assertForbidden();
});

it('shows module and task counts on the admin details tab', function () {
    bbTask($this->fixed, $this->admin, ['title' => 'A', 'board_column_id' => $this->done]);
    bbTask($this->fixed, $this->admin, ['title' => 'B', 'client_visible' => false]);
    bbTask($this->hourly, $this->admin, ['title' => 'C', 'board_column_id' => $this->done]);
    bbTask($this->hidden, $this->admin, ['title' => 'D']);
    bbTask($this->hidden, $this->admin, ['title' => 'Idea', 'is_idea' => true]);

    Livewire::actingAs($this->admin)->test(Show::class, ['project' => $this->p])
        ->assertSee('Online store rebuild')->assertSee('Ship the shop')
        ->assertSeeInOrder(['Modules', '3', '1 completed', '2 pending', 'Tasks', '4', '2 completed', '2 pending']);
});

it('shows only client-visible counts on the portal details tab', function () {
    bbTask($this->fixed, $this->admin, ['title' => 'A', 'board_column_id' => $this->done]);
    bbTask($this->fixed, $this->admin, ['title' => 'B', 'client_visible' => false]);
    bbTask($this->hourly, $this->admin, ['title' => 'C', 'board_column_id' => $this->done]);
    bbTask($this->hidden, $this->admin, ['title' => 'D']);

    Livewire::actingAs($this->cu)->test(ProjectView::class, ['project' => $this->p])->set('tab', 'details')
        ->assertSee('Online store rebuild')->assertSee('Ship the shop')
        ->assertSeeInOrder(['Modules', '2', '1 completed', '1 pending', 'Tasks', '2', '2 completed', '0 pending']);
});

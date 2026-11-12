<?php

use App\Livewire\Clients\Show;
use App\Livewire\Projects\Index;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->client = Client::create(['name' => 'ToggleCo', 'currency' => 'USD']);
});

it('switches the projects page between cards and list via the URL', function () {
    Project::create(['client_id' => $this->client->id, 'name' => 'Alpha', 'status' => 'active']);
    Livewire::actingAs($this->admin)->test(Index::class)
        ->assertSeeHtml('data-test="project-card"')->assertDontSeeHtml('data-test="project-list"')
        ->set('view', 'list')->assertSeeHtml('data-test="project-list"')->assertSeeHtml('data-test="project-row"')->assertDontSeeHtml('data-test="project-card"')
        ->set('view', 'bogus')->assertSet('view', 'cards');
});

it('shows started, completed and last-worked dates', function () {
    Project::create(['client_id' => $this->client->id, 'name' => 'Done', 'status' => 'completed', 'start_date' => '2026-01-05', 'completed_at' => '2026-03-10']);
    $held = Project::create(['client_id' => $this->client->id, 'name' => 'Held', 'status' => 'on_hold', 'start_date' => '2026-02-01']);
    $module = Module::create(['project_id' => $held->id, 'name' => 'M', 'position' => 1]);
    $task = Task::create(['module_id' => $module->id, 'creator_id' => $this->admin->id, 'title' => 'T', 'status' => 'published', 'scope' => 'in', 'position' => 1]);
    TimeEntry::create(['timeable_type' => $task->getMorphClass(), 'timeable_id' => $task->id, 'user_id' => $this->admin->id, 'hours' => 2, 'worked_on' => '2026-04-20']);

    expect(Index::lastWorked([$held->id])[$held->id]->toDateString())->toBe('2026-04-20');

    foreach (['cards', 'list'] as $view) {
        Livewire::withQueryParams(['view' => $view])->actingAs($this->admin)->test(Index::class)
            ->assertSee('Started Jan 5, 2026')->assertSee('Completed Mar 10, 2026')->assertSee('Last worked Apr 20, 2026');
    }
    Livewire::actingAs($this->admin)->test(Show::class, ['client' => $this->client])
        ->assertSeeHtml('data-test="view-toggle"')->assertSeeHtml('data-test="project-list"')
        ->assertSee('Completed Mar 10, 2026')->assertSee('Last worked Apr 20, 2026');
});

it('remembers the cards/list choice per page, and a ?view= in the URL still wins', function () {
    $u = User::factory()->create();
    $u->assignRole('super_admin');

    Livewire::actingAs($u)->test(Index::class)->set('view', 'list');
    expect(collect(\Illuminate\Support\Facades\Cookie::getQueuedCookies())->first(fn ($c) => $c->getName() === 'view_projects_index')?->getValue())->toBe('list');

    Livewire::withCookies(['view_projects_index' => 'list'])->actingAs($u)->test(Index::class)->assertSet('view', 'list');
    Livewire::withQueryParams(['view' => 'cards'])->withCookies(['view_projects_index' => 'list'])->actingAs($u)->test(Index::class)->assertSet('view', 'cards');
    Livewire::withCookies(['view_projects_index' => 'bogus'])->actingAs($u)->test(Index::class)->assertSet('view', 'cards');
});

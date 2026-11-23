<?php

use App\Livewire\Sprints\Mine;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->withoutVite();
});

it('shows my tasks from active sprints only, grouped by project, and All shows others', function () {
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    $other = User::factory()->create();
    $other->assignRole('developer');

    $task = function (Project $p, ?int $sprintId, string $title, User $who, string $stage = 'not_started') use ($admin) {
        $m = $p->modules()->first() ?? Module::create(['project_id' => $p->id, 'name' => 'Core', 'position' => 1]);
        $t = Task::create(['module_id' => $m->id, 'creator_id' => $admin->id, 'title' => $title, 'status' => 'published', 'scope' => 'in', 'estimate_hours' => 2,
            'sprint_id' => $sprintId, 'board_column_id' => $p->columns()->where('stage', $stage)->first()->id]);
        $t->assignees()->attach($who->id);
    };

    foreach (['Alpha', 'Beta'] as $name) {
        $p = Project::create(['client_id' => $client->id, 'name' => $name, 'status' => 'active']);
        ProjectMember::create(['project_id' => $p->id, 'user_id' => $dev->id, 'role_on_project' => 'developer', 'access_scope' => 'all', 'active' => true]);
        $active = $p->sprints()->create(['name' => "$name S1", 'status' => 'active', 'starts_on' => today(), 'ends_on' => today()->addDays(6)]);
        $planned = $p->sprints()->create(['name' => "$name S2", 'status' => 'planned', 'starts_on' => today()->addWeek(), 'ends_on' => today()->addWeek()->addDays(6)]);
        $task($p, $active->id, "$name mine todo", $dev);
        $task($p, $active->id, "$name mine done", $dev, 'done');
        $task($p, $active->id, "$name theirs", $other);
        $task($p, $planned->id, "$name planned", $dev);
        $task($p, null, "$name backlog", $dev);
    }

    $this->actingAs($dev)->get(route('sprints.mine'))->assertOk()->assertSee('My sprint');

    $c = Livewire::actingAs($dev)->test(Mine::class)
        ->assertSeeInOrder(['Alpha S1', 'Alpha mine todo', 'Alpha mine done', 'Beta S1', 'Beta mine todo'])
        ->assertDontSee('theirs')->assertDontSee('planned')->assertDontSee('backlog')
        ->assertViewHas('open', 2)->assertViewHas('done', 2);
    expect($c->viewData('groups'))->toHaveCount(2);

    $c->set('show', 'all')->assertDontSee('Alpha theirs')->assertDontSee('Beta theirs')->assertDontSee('Alpha planned');
});

it('shows an empty state with no active sprints', function () {
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    Livewire::actingAs($dev)->test(Mine::class)->assertSee('No active sprints');
});

<?php

use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\RecurringCharge;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
});

function pdSetup(): array
{
    $client = Client::create(['name' => 'Mine Co', 'currency' => 'USD']);
    $other = Client::create(['name' => 'Other Co', 'currency' => 'USD']);
    $user = User::factory()->create(['client_id' => $client->id])->assignRole('client');
    $live = Project::create(['client_id' => $client->id, 'key' => 'LIV', 'name' => 'Live Site', 'status' => 'active', 'initial_budget' => '5000.00', 'deadline' => now()->addDays(60)->toDateString()]);
    Project::create(['client_id' => $client->id, 'name' => 'Draft Idea', 'status' => 'draft']);
    Project::create(['client_id' => $client->id, 'name' => 'Paused Work', 'status' => 'on_hold']);
    Project::create(['client_id' => $other->id, 'name' => 'Other Secret', 'status' => 'active']);

    $m = Module::create(['project_id' => $live->id, 'name' => 'Shop', 'client_visible' => true]);
    $done = $live->columns()->where('stage', 'done')->value('id');
    $todo = $live->columns()->where('stage', 'not_started')->value('id');
    $mk = fn (string $title, int $col, array $extra = []) => Task::create($extra + [
        'module_id' => $m->id, 'creator_id' => $user->id, 'title' => $title, 'status' => TaskStatus::Published->value,
        'scope' => 'in', 'client_visible' => true, 'board_column_id' => $col,
    ]);
    $mk('Finished Cart', $done);
    $mk('Coming Soon', $todo, ['deadline' => now()->addDays(5)->toDateString()]);
    $mk('Hidden Internal', $todo, ['client_visible' => false]);
    RecurringCharge::create(['client_id' => $client->id, 'description' => 'Hosting', 'amount_cents' => 1000, 'frequency' => 'monthly', 'next_due_on' => now()->addMonth(), 'active' => true]);

    return [$user, $live];
}

it('projects page lists only own visible-status projects with stats', function () {
    [$user] = pdSetup();

    actingAs($user)->get(route('portal.projects'))->assertOk()
        ->assertSee('Live Site')->assertSee('$5,000.00')->assertSee('50%')
        ->assertSeeInOrder(['Modules', '0 / 1', 'Tasks', '1 / 2'])
        ->assertDontSee('Draft Idea')->assertDontSee('Paused Work')->assertDontSee('Other Secret')->assertDontSee('Hidden Internal');
});

it('dashboard shows stats, approvals and deadlines without hidden data', function () {
    [$user] = pdSetup();

    actingAs($user)->get(route('portal.dashboard'))->assertOk()
        ->assertSee('Live Site')->assertSee('1 of 2')->assertSee('Active subscriptions')
        ->assertSee('Finished Cart')->assertSee('Coming Soon')
        ->assertDontSee('Draft Idea')->assertDontSee('Paused Work')->assertDontSee('Other Secret')->assertDontSee('Hidden Internal');
});

it('draft project page is closed to the client', function () {
    [$user] = pdSetup();
    $draft = Project::where('name', 'Draft Idea')->first();

    actingAs($user)->get(route('portal.project', $draft))->assertForbidden();
});

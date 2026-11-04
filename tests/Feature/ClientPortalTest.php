<?php

use App\Enums\AccessScope;
use App\Enums\TaskScope;
use App\Enums\TaskStatus;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\ProjectView;
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

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
});

// ── helpers ───────────────────────────────────────────────────────────────────

/** Create a Client + one client-role User linked to it. */
function portalClient(string $name = 'AcmeCorp'): array
{
    $client = Client::create(['name' => $name, 'currency' => 'USD']);
    $user   = User::factory()->create(['client_id' => $client->id]);
    $user->assignRole('client');
    return [$client, $user];
}

function portalProject(Client $client, string $status = 'active'): Project
{
    return Project::create(['client_id' => $client->id, 'name' => 'Portal Project', 'status' => $status]);
}

function portalModule(Project $project): Module
{
    return Module::create(['project_id' => $project->id, 'name' => 'PMod', 'position' => 1]);
}

/** Internal (creator) user — not client role. */
function internalUser(string $role = 'project_manager'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    return $user;
}

function portalTask(Module $module, User $creator, array $attrs = []): Task
{
    return Task::create(array_merge([
        'module_id'      => $module->id,
        'creator_id'     => $creator->id,
        'title'          => 'Portal Task',
        'status'         => TaskStatus::Published,
        'priority'       => 1,
        'estimate_hours' => 4.0,
        'scope'          => TaskScope::In,
        'position'       => 1,
        'client_visible' => true,
    ], $attrs));
}

// ── cross-client access: DENY ─────────────────────────────────────────────────

it('client A cannot load client B project on Dashboard', function () {
    [$clientA, $userA] = portalClient('ClientA');
    [$clientB, $userB] = portalClient('ClientB');

    $projectB = portalProject($clientB);

    // userA's dashboard must list only ClientA's projects — not B's
    $component = Livewire::actingAs($userA)
        ->test(Dashboard::class);

    $projects = $component->get('projects');
    expect($projects->pluck('id'))->not->toContain($projectB->id);
});

it('client A gets 403 trying to view client B project via ProjectView', function () {
    [$clientA, $userA] = portalClient('ClientA');
    [$clientB]         = portalClient('ClientB');

    $projectB = portalProject($clientB);

    Livewire::actingAs($userA)
        ->test(ProjectView::class, ['project' => $projectB])
        ->assertForbidden();
});

// ── portal route access ───────────────────────────────────────────────────────

it('non-client user is denied the portal dashboard route', function () {
    $user = internalUser('project_manager');

    $this->actingAs($user)
        ->get(route('portal.dashboard'))
        ->assertForbidden();
});

it('developer role is blocked from portal routes at route middleware level', function () {
    $user = internalUser('developer');

    $this->actingAs($user)
        ->get(route('portal.dashboard'))
        ->assertForbidden();
});

it('client user can access portal dashboard route', function () {
    [$client, $user] = portalClient();

    $this->actingAs($user)
        ->get(route('portal.dashboard'))
        ->assertOk();
});

it('client user can access their project via portal route', function () {
    [$client, $user] = portalClient();
    $project = portalProject($client);

    $this->actingAs($user)
        ->get(route('portal.project', $project))
        ->assertOk();
});

// ── task visibility: client sees only client_visible tasks ────────────────────

it('client sees client_visible published tasks on ProjectView', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    portalTask($module, $internal, ['title' => 'Visible Task', 'client_visible' => true]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->assertSee('Visible Task');
});

it('client does NOT see internal (non-client-visible) tasks on ProjectView', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    portalTask($module, $internal, ['title' => 'Internal Task', 'client_visible' => false]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->assertDontSee('Internal Task');
});

it('client does NOT see draft tasks on ProjectView', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    portalTask($module, $internal, [
        'title'          => 'Draft Client Task',
        'client_visible' => true,
        'status'         => TaskStatus::Draft,
    ]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->assertDontSee('Draft Client Task');
});

// ── budget amount / cost never exposed ────────────────────────────────────────────────

it('portal ProjectView response contains no budget amount field', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    portalTask($module, $internal, [
        'title'          => 'Priced Task',
        'client_visible' => true,
        'budget_amount'          => 9999.00,
    ]);

    // The rendered HTML must not contain the numeric budget amount value
    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->assertDontSee('9999');
});

it('portal dashboard response does not expose cost_rate_amount', function () {
    [$client, $clientUser] = portalClient();

    // Render the dashboard; it must not reference cost_rate_amount at all
    $component = Livewire::actingAs($clientUser)
        ->test(Dashboard::class);

    $html = $component->html();
    expect($html)->not->toContain('cost_rate');
});

// ── client capabilities ───────────────────────────────────────────────────────

it('client can create a task (lands client_visible=true, published, scope=in)', function () {
    [$client, $clientUser] = portalClient();
    $project = portalProject($client);
    $module  = portalModule($project);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->set('newTitle', 'My New Request')
        ->call('createTask');

    $task = Task::where('title', 'My New Request')->firstOrFail();
    expect($task->client_visible)->toBeTrue();
    expect($task->status)->toBe(TaskStatus::Published);
    expect($task->scope->value)->toBe('in');
});

it('client can add a comment to a client_visible task', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    $task = portalTask($module, $internal, ['client_visible' => true]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->set('commentBody', 'Looks good to me!')
        ->call('addComment', $task->id);

    expect($task->comments()->where('author_id', $clientUser->id)->count())->toBe(1);
});

it('client can change priority of a client_visible task', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    $task = portalTask($module, $internal, ['client_visible' => true, 'priority' => 1]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->call('setPriority', $task->id, 3);

    expect($task->fresh()->priority)->toBe(3);
});

it('client can change labels of a client_visible task', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    $label = Label::create(['name' => 'Bug', 'color' => '#ff0000']);
    $task  = portalTask($module, $internal, ['client_visible' => true]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->call('setLabels', $task->id, [$label->id]);

    expect($task->fresh()->labels()->pluck('labels.id'))->toContain($label->id);
});

it('client can approve a Done task (client_approval becomes approved)', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    $task = portalTask($module, $internal, [
        'client_visible' => true,
        'board_column_id' => colId($module, 'Done'),
    ]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->call('approveTask', $task->id);

    expect($task->fresh()->client_approval)->toBe('approved');
});

it('client cannot approve a task that is NOT in Done column', function () {
    [$client, $clientUser] = portalClient();
    $project  = portalProject($client);
    $module   = portalModule($project);
    $internal = internalUser();

    $task = portalTask($module, $internal, [
        'client_visible' => true,
    ]);

    Livewire::actingAs($clientUser)
        ->test(ProjectView::class, ['project' => $project])
        ->call('approveTask', $task->id)
        ->assertStatus(422);

    // Approval should remain null (not approved)
    expect($task->fresh()->client_approval)->toBeNull();
});

it('client cannot approve a task from another client', function () {
    [$clientA, $userA] = portalClient('A');
    [$clientB]         = portalClient('B');

    $projectB = portalProject($clientB);
    $moduleB  = portalModule($projectB);
    $internal = internalUser();

    $taskB = portalTask($moduleB, $internal, [
        'client_visible' => true,
        'board_column_id' => colId($moduleB, 'Done'),
    ]);

    // userA mounts their own project (they have none here, use projectA)
    $projectA = portalProject($clientA);

    Livewire::actingAs($userA)
        ->test(ProjectView::class, ['project' => $projectA])
        ->call('approveTask', $taskB->id)
        ->assertForbidden();

    expect($taskB->fresh()->client_approval)->toBeNull();
});

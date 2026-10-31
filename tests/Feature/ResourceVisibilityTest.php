<?php

use App\Enums\AccessScope;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Resource;
use App\Models\ResourceItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function rvSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $resource = $project->resources()->create(['title' => 'Creds']);

    $staff = User::factory()->create();
    $staff->assignRole('developer');
    $other = User::factory()->create();
    $other->assignRole('developer');
    foreach ([$staff, $other] as $u) {
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    }
    $k = User::factory()->create(['client_id' => $client->id]);
    $k->assignRole('client');

    $mk = fn ($label) => $resource->items()->create(['type' => 'text', 'label' => $label, 'value' => 'x', 'created_by' => $staff->id]);
    [$a, $b, $c] = [$mk('A'), $mk('B'), $mk('C')];
    $a->assignees()->attach($staff);
    $c->assignees()->attach($k);

    return compact('client', 'project', 'resource', 'staff', 'other', 'k', 'a', 'b', 'c');
}

function rvLabels(User $u): array
{
    return ResourceItem::visibleTo($u)->orderBy('label')->pluck('label')->all();
}

it('shows each user only their assigned items', function () {
    extract(rvSetup());

    expect(rvLabels($staff))->toBe(['A'])
        ->and(rvLabels($k))->toBe(['C'])
        ->and(rvLabels($other))->toBe([]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    expect(rvLabels($admin))->toBe(['A', 'B', 'C']);
});

it('shows resource only to users with a visible item', function () {
    extract(rvSetup());

    expect(Resource::visibleTo($staff)->count())->toBe(1)
        ->and(Resource::visibleTo($other)->count())->toBe(0);
});

it('hides items when membership is inactive', function () {
    extract(rvSetup());
    ProjectMember::where('user_id', $staff->id)->update(['active' => false]);

    expect(rvLabels($staff))->toBe([])->and(Resource::visibleTo($staff)->count())->toBe(0);
});

it('hides items from a client of a different client', function () {
    extract(rvSetup());
    $c2 = Client::create(['name' => 'Other', 'currency' => 'USD']);
    $k->update(['client_id' => $c2->id]);

    expect(rvLabels($k))->toBe([]);
});

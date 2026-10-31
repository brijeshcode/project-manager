<?php

use App\Enums\AccessScope;
use App\Livewire\Portal\ProjectView;
use App\Livewire\Resources\Panel;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ResourceItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function rpSetup(): array
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $resource = $project->resources()->create(['title' => 'Creds']);
    $hidden = $project->resources()->create(['title' => 'Secret']);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $staff = User::factory()->create();
    $staff->assignRole('developer');
    $outsider = User::factory()->create();
    $outsider->assignRole('developer');
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $staff->id, 'role_on_project' => 'developer', 'access_scope' => AccessScope::All, 'active' => true]);
    $k = User::factory()->create(['client_id' => $client->id]);
    $k->assignRole('client');

    $mk = fn ($r, $l) => $r->items()->create(['type' => 'text', 'label' => $l, 'value' => 'x', 'created_by' => $admin->id]);
    $a = $mk($resource, 'LabelA');
    $b = $mk($resource, 'LabelB');
    $c = $mk($resource, 'LabelC');
    $mk($hidden, 'LabelH')->assignees()->attach($admin);
    $a->assignees()->attach($staff);
    $c->assignees()->attach($k);

    return compact('client', 'project', 'resource', 'hidden', 'admin', 'staff', 'outsider', 'k', 'a', 'b', 'c');
}

it('admin adds a resource', function () {
    extract(rpSetup());
    Livewire::actingAs($admin)->test(Panel::class, ['project' => $project])
        ->set('newTitle', 'New one')->call('addResource')->assertHasNoErrors();
    expect($project->resources()->where('title', 'New one')->exists())->toBeTrue();
});

it('non-admin cannot add a resource', function () {
    extract(rpSetup());
    Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->set('newTitle', 'X')->call('addResource')->assertForbidden();
});

it('staff sees only assigned items', function () {
    extract(rpSetup());
    Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->assertSee('LabelA')->assertDontSee('LabelB')->assertDontSee('LabelC')->assertDontSee('Secret');
});

it('staff adds a text item and is auto-assigned', function () {
    extract(rpSetup());
    Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->set("itemLabel.{$resource->id}", 'Mine')->set("itemValue.{$resource->id}", 'pw')
        ->call('addTextItem', $resource->id)->assertHasNoErrors();
    $item = ResourceItem::where('label', 'Mine')->firstOrFail();
    expect($item->created_by)->toBe($staff->id)->and($item->type)->toBe('text')
        ->and($item->assignees->pluck('id')->all())->toBe([$staff->id])
        ->and(ResourceItem::visibleTo($staff)->where('label', 'LabelB')->exists())->toBeFalse();
});

it('staff cannot add to an invisible resource', function () {
    extract(rpSetup());
    expect(fn () => Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->set("itemLabel.{$hidden->id}", 'L')->set("itemValue.{$hidden->id}", 'v')
        ->call('addTextItem', $hidden->id))->toThrow(ModelNotFoundException::class);
});

it('rejects whitespace and oversize values', function () {
    extract(rpSetup());
    $t = Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->set("itemLabel.{$resource->id}", 'W')->set("itemValue.{$resource->id}", "   \n ")
        ->call('addTextItem', $resource->id)->assertHasErrors(["itemValue.{$resource->id}"]);
    $t->set("itemValue.{$resource->id}", str_repeat('a', 10001))
        ->call('addTextItem', $resource->id)->assertHasErrors(["itemValue.{$resource->id}"]);
    expect(ResourceItem::where('label', 'W')->exists())->toBeFalse();
});

it('only admin sets assignees', function () {
    extract(rpSetup());
    Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->call('setAssignees', $a->id, [$staff->id])->assertForbidden();
});

it('admin assigns members and rejects non-members', function () {
    extract(rpSetup());
    $t = Livewire::actingAs($admin)->test(Panel::class, ['project' => $project]);
    $t->call('setAssignees', $b->id, [$staff->id, $k->id])->assertHasNoErrors();
    expect($b->assignees()->pluck('users.id')->sort()->values()->all())->toBe(collect([$staff->id, $k->id])->sort()->values()->all());

    $t->call('setAssignees', $b->id, [$outsider->id])->assertHasErrors('assignees');
    expect($b->assignees()->count())->toBe(2);
});

it('client sees only own item on portal; outsiders are forbidden', function () {
    extract(rpSetup());
    Livewire::actingAs($k)->test(ProjectView::class, ['project' => $project])
        ->assertSee('LabelC')->assertDontSee('LabelA')->assertDontSee('LabelB');
    Livewire::actingAs($outsider)->test(Panel::class, ['project' => $project])->assertForbidden();
});

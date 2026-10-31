<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ResourceItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function rmItem(): ResourceItem
{
    $client = Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $resource = $project->resources()->create(['title' => 'Creds']);

    return $resource->items()->create([
        'type' => 'text', 'label' => 'DB', 'value' => 'hunter2', 'created_by' => User::factory()->create()->id,
    ]);
}

it('encrypts item value at rest', function () {
    $item = rmItem();

    expect($item->fresh()->value)->toBe('hunter2');
    $raw = DB::table('resource_items')->value('value');
    expect($raw)->not->toBe('hunter2')->and($raw)->not->toContain('hunter2');
});

it('hides value from toArray', function () {
    expect(rmItem()->toArray())->not->toHaveKey('value');
});

it('assigns users and comments', function () {
    $item = rmItem();
    $user = User::factory()->create();
    $item->assignees()->attach($user);
    $item->comments()->create(['author_id' => $user->id, 'body' => 'hi']);

    expect($item->assignees()->count())->toBe(1)->and($item->comments()->count())->toBe(1);
});

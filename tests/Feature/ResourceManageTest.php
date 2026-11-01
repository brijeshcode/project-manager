<?php

use App\Livewire\Resources\Panel;
use App\Models\Comment;
use App\Models\Resource;
use App\Models\ResourceItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
});

function rmFileItem(array $s): ResourceItem
{
    Livewire::actingAs($s['admin'])->test(Panel::class, ['project' => $s['project']])
        ->set("itemLabel.{$s['resource']->id}", 'Doc')
        ->set("upload.{$s['resource']->id}", UploadedFile::fake()->create('d.pdf', 5, 'application/pdf'))
        ->call('addFileItem', $s['resource']->id, 'file')->assertHasNoErrors();

    return ResourceItem::where('label', 'Doc')->firstOrFail();
}

it('admin deletes an item along with its file and comments', function () {
    $s = rpSetup();
    $item = rmFileItem($s);
    $item->comments()->create(['author_id' => $s['admin']->id, 'body' => 'hi']);
    Storage::disk('local')->assertExists($item->value);

    Livewire::actingAs($s['admin'])->test(Panel::class, ['project' => $s['project']])->call('deleteItem', $item->id);

    expect(ResourceItem::find($item->id))->toBeNull()->and(Comment::count())->toBe(0);
    Storage::disk('local')->assertMissing($item->value);
});

it('admin deletes a resource with all its items and files', function () {
    $s = rpSetup();
    $item = rmFileItem($s);

    Livewire::actingAs($s['admin'])->test(Panel::class, ['project' => $s['project']])->call('deleteResource', $s['resource']->id);

    expect(Resource::find($s['resource']->id))->toBeNull()->and(ResourceItem::where('resource_id', $s['resource']->id)->count())->toBe(0);
    Storage::disk('local')->assertMissing($item->value);
    expect(Resource::find($s['hidden']->id))->not->toBeNull();
});

it('non-admins cannot delete or edit', function () {
    $s = rpSetup();
    $t = fn () => Livewire::actingAs($s['staff'])->test(Panel::class, ['project' => $s['project']]);
    $t()->call('deleteItem', $s['a']->id)->assertForbidden();
    $t()->call('deleteResource', $s['resource']->id)->assertForbidden();
    $t()->set("editLabel.{$s['a']->id}", 'X')->call('updateItem', $s['a']->id)->assertForbidden();
    expect($s['a']->fresh()->label)->toBe('LabelA')->and(Resource::count())->toBe(2);
});

it('admin edits label and text value', function () {
    $s = rpSetup();
    Livewire::actingAs($s['admin'])->test(Panel::class, ['project' => $s['project']])
        ->set("editLabel.{$s['a']->id}", ' Renamed ')->set("editValue.{$s['a']->id}", 'new-secret')
        ->call('updateItem', $s['a']->id)->assertHasNoErrors();
    expect($s['a']->fresh()->label)->toBe('Renamed')->and($s['a']->fresh()->value)->toBe('new-secret');
});

it('rejects an empty label or value on edit', function () {
    $s = rpSetup();
    Livewire::actingAs($s['admin'])->test(Panel::class, ['project' => $s['project']])
        ->set("editLabel.{$s['a']->id}", '')->set("editValue.{$s['a']->id}", ' ')
        ->call('updateItem', $s['a']->id)->assertHasErrors(["editLabel.{$s['a']->id}", "editValue.{$s['a']->id}"]);
    expect($s['a']->fresh()->label)->toBe('LabelA');
});

it('refuses an unknown item type at model level', function () {
    $s = rpSetup();
    expect(fn () => $s['resource']->items()->create(['type' => 'video', 'label' => 'x', 'value' => 'y', 'created_by' => $s['admin']->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('a client user without a client link sees nothing', function () {
    $s = rpSetup();
    $orphan = User::factory()->create(['client_id' => null]);
    $orphan->assignRole('client');
    $s['a']->assignees()->attach($orphan);

    expect(ResourceItem::visibleTo($orphan)->count())->toBe(0)->and(Resource::visibleTo($orphan)->count())->toBe(0);
});

it('serves downloads with nosniff and rejects non-numeric ids', function () {
    $s = rpSetup();
    $item = rmFileItem($s);
    $this->actingAs($s['admin'])->get(route('resource-items.download', $item->id))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($s['admin'])->get('/resource-items/abc/download')->assertNotFound();
});

it('lists comments oldest first', function () {
    $s = rpSetup();
    $s['a']->comments()->create(['author_id' => $s['staff']->id, 'body' => 'first-comment', 'created_at' => now()->subDay()]);
    $s['a']->comments()->create(['author_id' => $s['staff']->id, 'body' => 'second-comment']);

    Livewire::actingAs($s['staff'])->test(Panel::class, ['project' => $s['project']])
        ->assertSeeInOrder(['first-comment', 'second-comment']);
});

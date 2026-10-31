<?php

use App\Livewire\Resources\Panel;
use App\Models\ResourceItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
});

function rfUpload($user, $project, $resource, $type, $file, $label = 'Logo')
{
    return Livewire::actingAs($user)->test(Panel::class, ['project' => $project])
        ->set("itemLabel.{$resource->id}", $label)
        ->set("upload.{$resource->id}", $file)
        ->call('addFileItem', $resource->id, $type);
}

it('stores an uploaded image privately and creates an assigned item', function () {
    extract(rpSetup());
    rfUpload($staff, $project, $resource, 'image', UploadedFile::fake()->image('logo.png'))->assertHasNoErrors();
    $item = ResourceItem::where('label', 'Logo')->firstOrFail();
    expect($item->type)->toBe('image')->and($item->original_name)->toBe('logo.png')
        ->and($item->value)->toStartWith('resources/')
        ->and($item->creator->id)->toBe($staff->id)
        ->and($item->assignees->pluck('id')->all())->toBe([$staff->id]);
    Storage::disk('local')->assertExists($item->value);
    Storage::disk('public')->assertMissing($item->value);
});

it('lets an assigned user download and blocks others', function () {
    extract(rpSetup());
    rfUpload($staff, $project, $resource, 'file', UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'));
    $item = ResourceItem::where('label', 'Logo')->firstOrFail();
    $this->actingAs($staff)->get(route('resource-items.download', $item->id))->assertOk();
    $this->actingAs($outsider)->get(route('resource-items.download', $item->id))->assertNotFound();
    auth()->logout();
    $this->get(route('resource-items.download', $item->id))->assertRedirect(route('login'));
});

it('returns 404 downloading a text item', function () {
    extract(rpSetup());
    $a->assignees()->attach($admin);
    $this->actingAs($staff)->get(route('resource-items.download', $a->id))->assertNotFound();
    $this->actingAs($admin)->get(route('resource-items.download', $a->id))->assertNotFound();
});

it('rejects wrong mime, oversize and bad types', function () {
    extract(rpSetup());
    rfUpload($staff, $project, $resource, 'image', UploadedFile::fake()->create('a.zip', 5, 'application/zip'))->assertHasErrors();
    rfUpload($staff, $project, $resource, 'file', UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'))->assertHasErrors();
    rfUpload($staff, $project, $resource, 'text', UploadedFile::fake()->image('a.png'))->assertHasErrors();
    expect(ResourceItem::where('label', 'Logo')->count())->toBe(0);
});

it('sanitizes the stored original name and escapes it', function () {
    extract(rpSetup());
    $t = rfUpload($staff, $project, $resource, 'file', UploadedFile::fake()->create('../../evil<script>.pdf', 5, 'application/pdf'));
    $t->assertHasNoErrors();
    $item = ResourceItem::where('label', 'Logo')->firstOrFail();
    expect($item->value)->toStartWith('resources/')->and($item->value)->not->toContain('evil')
        ->and($item->original_name)->not->toContain('/')->not->toContain(chr(92));
    expect($t->html())->not->toContain('<script>');
});

it('does not let a user upload to a resource they cannot see', function () {
    extract(rpSetup());
    expect(fn () => rfUpload($staff, $project, $hidden, 'file', UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

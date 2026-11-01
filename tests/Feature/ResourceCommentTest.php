<?php

use App\Livewire\Resources\Panel;
use App\Models\Comment;
use App\Models\Project;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;


uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('assigned user comments', function () {
    extract(rpSetup());
    Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->set("commentBody.{$a->id}", 'hello <b>x</b>')->call('addComment', $a->id)->assertHasNoErrors()
        ->assertSee('hello &lt;b&gt;x&lt;/b&gt;', false);
    expect($a->comments()->count())->toBe(1)->and($a->comments()->first()->author_id)->toBe($staff->id);
});

it('admin comments on any item', function () {
    extract(rpSetup());
    Livewire::actingAs($admin)->test(Panel::class, ['project' => $project])
        ->set("commentBody.{$b->id}", 'hi')->call('addComment', $b->id)->assertHasNoErrors();
    expect($b->comments()->count())->toBe(1);
});

it('unassigned user gets 404 and nothing is written', function () {
    extract(rpSetup());
    expect(fn () => Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])
        ->set("commentBody.{$b->id}", 'hi')->call('addComment', $b->id))->toThrow(ModelNotFoundException::class);
    expect(Comment::count())->toBe(0);
});

it('item of another project is 404', function () {
    extract(rpSetup());
    $other = Project::create(['client_id' => $client->id, 'name' => 'Other', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $item = $other->resources()->create(['title' => 'R'])->items()->create(['type' => 'text', 'label' => 'L', 'value' => 'v', 'created_by' => $admin->id]);
    expect(fn () => Livewire::actingAs($admin)->test(Panel::class, ['project' => $project])
        ->set("commentBody.{$item->id}", 'hi')->call('addComment', $item->id))->toThrow(ModelNotFoundException::class);
    expect(Comment::count())->toBe(0);
});

it('empty or oversized body fails validation', function () {
    extract(rpSetup());
    $t = Livewire::actingAs($staff)->test(Panel::class, ['project' => $project]);
    $t->call('addComment', $a->id)->assertHasErrors(["commentBody.{$a->id}" => 'required']);
    $t->set("commentBody.{$a->id}", str_repeat('x', 2001))->call('addComment', $a->id)->assertHasErrors(["commentBody.{$a->id}" => 'max']);
    expect(Comment::count())->toBe(0);
});

it('comments are visible only to those who see the item', function () {
    extract(rpSetup());
    $a->comments()->create(['author_id' => $staff->id, 'body' => 'SecretTalk']);
    Livewire::actingAs($staff)->test(Panel::class, ['project' => $project])->assertSee('SecretTalk');
    Livewire::actingAs($k)->test(Panel::class, ['project' => $project])->assertDontSee('SecretTalk');
});

it('client sees You and Team, never staff names; staff sees names', function () {
    extract(rpSetup());
    $c->comments()->create(['author_id' => $k->id, 'body' => 'mine']);
    $c->comments()->create(['author_id' => $admin->id, 'body' => 'reply']);
    Livewire::actingAs($k)->test(Panel::class, ['project' => $project])
        ->assertSee('You')->assertSee('Team')->assertDontSee($admin->name);
    Livewire::actingAs($admin)->test(Panel::class, ['project' => $project])->assertSee($k->name)->assertSee($admin->name);
});

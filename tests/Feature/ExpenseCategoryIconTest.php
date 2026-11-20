<?php

use App\Livewire\Settings\ExpenseCategories;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');
    $this->admin = User::factory()->create()->assignRole('super_admin');
});

function catPage()
{
    return Livewire::actingAs(test()->admin)->test(ExpenseCategories::class);
}

it('seeds the default categories with an icon and colour', function () {
    expect(ExpenseCategory::where('name', 'Server')->first())->icon->toBe('server')->color->toBe('#2563eb');
});

it('saves a colour with a curated icon or an emoji', function () {
    catPage()->call('create')->set('name', 'Hosting')->set('color', '#ff0000')->set('icon', 'server')->call('save')->assertHasNoErrors();
    expect(ExpenseCategory::where('name', 'Hosting')->first())->color->toBe('#ff0000')->icon->toBe('server');

    $c = ExpenseCategory::where('name', 'Hosting')->first();
    catPage()->call('edit', $c->id)->set('icon', '🔥')->call('save')->assertHasNoErrors();
    expect($c->fresh()->icon)->toBe('🔥');
    actingAs($this->admin)->get('/settings/expense-categories')->assertSee('🔥');
});

it('rejects an invalid colour and markup in the icon', function () {
    catPage()->call('create')->set('name', 'X')->set('color', 'red')->set('icon', '<b>')->call('save')->assertHasErrors(['color', 'icon']);
});

it('stores an uploaded image, shows it in the chip, and deletes it on replace, remove and delete', function () {
    catPage()->call('create')->set('name', 'Pics')->set('image', UploadedFile::fake()->image('a.png', 32, 32))->call('save')->assertHasNoErrors();
    $c = ExpenseCategory::where('name', 'Pics')->first();
    $first = $c->icon_path;
    expect($first)->toStartWith('expense-category-icons/');
    Storage::disk('public')->assertExists($first);
    Expense::create(['expense_category_id' => $c->id, 'description' => 'Thing', 'currency' => 'INR', 'amount_cents' => 100, 'exchange_rate' => '1', 'incurred_on' => today()]);
    actingAs($this->admin)->get(route('expenses.index'))->assertSee('<img src="'.Storage::disk('public')->url($first).'"', false);

    catPage()->call('edit', $c->id)->set('image', UploadedFile::fake()->image('b.jpg'))->call('save')->assertHasNoErrors();
    $second = $c->fresh()->icon_path;
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    catPage()->call('edit', $c->id)->call('removeImage')->call('save')->assertHasNoErrors();
    expect($c->fresh()->icon_path)->toBeNull();
    Storage::disk('public')->assertMissing($second);

    catPage()->call('edit', $c->id)->set('image', UploadedFile::fake()->image('c.gif'))->call('save');
    $third = $c->fresh()->icon_path;
    catPage()->call('delete', $c->id);
    Storage::disk('public')->assertMissing($third);
});

it('rejects svg and oversized uploads', function () {
    catPage()->call('create')->set('name', 'Vec')->set('image', UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))->call('save')->assertHasErrors('image');
    catPage()->call('create')->set('name', 'Big')->set('image', UploadedFile::fake()->image('big.png')->size(600))->call('save')->assertHasErrors('image');
    expect(ExpenseCategory::whereIn('name', ['Vec', 'Big'])->count())->toBe(0);
});

it('shows Uncategorised for an expense without a category', function () {
    $e = Expense::create(['description' => 'Loose', 'currency' => 'INR', 'amount_cents' => 100, 'exchange_rate' => '1', 'incurred_on' => today()]);
    actingAs($this->admin)->get(route('expenses.show', $e))->assertSee('Uncategorised');
});

it('still forbids non-money users', function () {
    actingAs(User::factory()->create()->assignRole('project_manager'))->get('/settings/expense-categories')->assertForbidden();
    Livewire::actingAs(User::factory()->create()->assignRole('project_manager'))->test(ExpenseCategories::class)->assertForbidden();
});

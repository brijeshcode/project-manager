<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Shared fixture for the Resource* feature tests. */
function rpSetup(): array
{
    $client = \App\Models\Client::create(['name' => 'Acme', 'currency' => 'USD']);
    $project = \App\Models\Project::create(['client_id' => $client->id, 'name' => 'Site', 'buffer_percent' => 0, 'start_date' => now(), 'status' => 'active']);
    $resource = $project->resources()->create(['title' => 'Creds']);
    $hidden = $project->resources()->create(['title' => 'Secret']);

    $admin = \App\Models\User::factory()->create();
    $admin->assignRole('super_admin');
    $staff = \App\Models\User::factory()->create();
    $staff->assignRole('developer');
    $outsider = \App\Models\User::factory()->create();
    $outsider->assignRole('developer');
    \App\Models\ProjectMember::create(['project_id' => $project->id, 'user_id' => $staff->id, 'role_on_project' => 'developer', 'access_scope' => \App\Enums\AccessScope::All, 'active' => true]);
    $k = \App\Models\User::factory()->create(['client_id' => $client->id]);
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


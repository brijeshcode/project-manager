<?php

use App\Enums\CostRateType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('assigns a role and recognizes it, without granting others', function () {
    $user = User::factory()->create();
    $user->assignRole('developer');

    expect($user->hasRole('developer'))->toBeTrue();
    expect($user->hasRole('super_admin'))->toBeFalse();
});

it('grants super_admin every ability via Gate::before', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    expect($user->can('anything-at-all'))->toBeTrue();
    expect($user->can('manage-projects'))->toBeTrue();
});

it('does not grant undefined abilities to a non super_admin', function () {
    $user = User::factory()->create();
    $user->assignRole('developer');

    expect($user->can('anything-at-all'))->toBeFalse();
});

it('seeds all six roles', function () {
    expect(\Spatie\Permission\Models\Role::pluck('name')->sort()->values()->all())
        ->toBe(['client', 'designer', 'developer', 'project_manager', 'super_admin', 'tester']);
});

it('persists and casts cost rate fields', function () {
    $user = User::factory()->create([
        'cost_rate_type' => CostRateType::Hourly,
        'cost_rate_amount' => 1500.50,
    ]);

    $fresh = $user->fresh();
    expect($fresh->cost_rate_type)->toBe(CostRateType::Hourly);
    expect($fresh->cost_rate_amount)->toBe(1500.50);
    expect($fresh->client_id)->toBeNull();
});

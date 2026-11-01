<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('layouts include the no-flash theme script', function () {
    $this->seed(RoleSeeder::class);
    $this->get('/login')
        ->assertSee("localStorage.getItem('theme')", false)
        ->assertSee('prefers-color-scheme', false);

    $admin = User::factory()->create()->assignRole('super_admin');
    $this->actingAs($admin)->get('/dashboard')
        ->assertSee("localStorage.getItem('theme')", false)
        ->assertSee('prefers-color-scheme', false);
});

it('tailwind config enables class dark mode and blue brand', function () {
    $c = file_get_contents(base_path('tailwind.config.js'));
    expect($c)->toContain("darkMode: 'class'")->toContain('#2563eb');
});

<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure the six roles exist.
        $this->call(RoleSeeder::class);
        $this->call(TaxonomySeeder::class);

        // Default system settings (never overwrites an edited value).
        foreach (['page_size' => '15', 'sidebar_layout' => 'full'] as $k => $v) {
            Settings::get($k) === null && Settings::set($k, $v, 'system');
        }

        // Idempotent super-admin login for local testing.
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        if (! $admin->hasRole('super_admin')) {
            $admin->assignRole('super_admin');
        }
    }
}

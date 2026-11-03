<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super admin bypasses every gate; return null to let others fall through.
        // task types and labels: super admin only (everyone else just assigns the existing ones)
        Gate::define('manage-taxonomy', fn ($user) => $user->hasRole('super_admin'));
        Gate::define('manage-money', fn ($user) => $user->hasRole('super_admin'));
        Gate::before(fn ($user, $ability) => $user->hasRole('super_admin') ? true : null);
    }
}

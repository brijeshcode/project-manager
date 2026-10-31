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
        Gate::define('manage-money', fn ($user) => false); // super_admin passes via Gate::before
        Gate::before(fn ($user, $ability) => $user->hasRole('super_admin') ? true : null);
    }
}

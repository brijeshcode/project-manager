<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Pagination\Paginator;
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
        Paginator::defaultView('pagination::tailwind');
        Paginator::defaultSimpleView('pagination::simple-tailwind');

        // Super admin bypasses every gate; return null to let others fall through.
        Gate::define('manage-money', fn ($user) => $user->hasRole('super_admin'));
        Gate::before(fn ($user, $ability) => $user->hasRole('super_admin') ? true : null);
    }
}

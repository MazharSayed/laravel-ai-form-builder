<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        // Render (and most hosts) terminate SSL at their proxy and forward
        // plain HTTP internally. In production, force Laravel to generate
        // https:// URLs so forms submit over a secure connection.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}

<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // Behind cPanel/HTTPS the subdomain is served over TLS; force generated
        // URLs (assets, form actions, redirects) to https so nothing loads as
        // mixed content on student.itfa.edu.ph.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}

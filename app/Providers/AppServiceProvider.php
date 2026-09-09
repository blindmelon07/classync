<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Shared hosts like Hostinger typically terminate SSL in front of
        // the app (the PHP process itself sees plain HTTP), so without this
        // Laravel generates http:// links even though the site is only
        // reachable over https://.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Backs $middleware->throttleApi() in bootstrap/app.php. 60/min per
        // authenticated user, or per IP for guests (i.e. hitting /register
        // and /login before a token exists -- those also get the stricter
        // throttle:6,1 defined directly on those routes).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}

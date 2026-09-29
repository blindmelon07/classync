<?php

namespace App\Providers;

use Google\Auth\Cache\FileSystemCacheItemPool;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as HttpClient;
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
        // Verifies Google ID tokens for POST /api/auth/google. Google's
        // default cert cache is in-memory, i.e. thrown away after every PHP
        // request -- the file pool keeps the certs between requests so a
        // sign-in only fetches them when Google rotates its keys (an unknown
        // `kid` triggers a refetch). Short HTTP timeouts keep that rare fetch
        // inside the app's 5-second budget.
        $this->app->bind(GoogleClient::class, function () {
            $client = new GoogleClient(['client_id' => config('services.google.client_id')]);
            $client->setCache(new FileSystemCacheItemPool(storage_path('framework/cache/google-certs')));
            $client->setHttpClient(new HttpClient(['connect_timeout' => 2, 'timeout' => 3]));

            return $client;
        });
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
        // authenticated user, or per IP for guests (i.e. hitting /register,
        // /login or /auth/google before a token exists -- those also get the
        // stricter throttles defined directly on those routes).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}

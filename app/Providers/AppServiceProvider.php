<?php

namespace App\Providers;

use App\Models\ApiClient;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureUrls();
        $this->registerApiRateLimiter();
        $this->registerGate();
    }

    /**
     * Align generated URLs (routes, assets, Livewire) with APP_URL.
     * Required when the app is served from a subdirectory such as /fttx/public.
     */
    private function configureUrls(): void
    {
        $appUrl = config('app.url');

        if (! is_string($appUrl) || $appUrl === '') {
            return;
        }

        URL::forceRootUrl(rtrim($appUrl, '/'));

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');

            if (config('session.secure') === null) {
                config(['session.secure' => true]);
            }
        }
    }

    /**
     * Per-API-key rate limiting, using the limit stored on each ApiClient.
     */
    private function registerApiRateLimiter(): void
    {
        RateLimiter::for('api-client', function (Request $request) {
            $client = $request->attributes->get('api_client');

            if ($client instanceof ApiClient) {
                return Limit::perMinute($client->rate_limit)->by('client:'.$client->id);
            }

            return Limit::perMinute(30)->by($request->ip());
        });
    }

    /**
     * Route every Gate ability through the user's role permissions, so
     * `@can('olt.manage')` / `$user->can(...)` and the `permission` middleware
     * all share one source of truth. Super admin is granted everything.
     */
    private function registerGate(): void
    {
        Gate::before(fn ($user) => $user->isSuperAdmin() ? true : null);

        Gate::define('access', fn ($user, string $permission) => $user->hasPermission($permission));

        // Allow @can('<permission-slug>') for any permission.
        foreach (Permissions::all() as $slug => $label) {
            Gate::define($slug, fn ($user) => $user->hasPermission($slug));
        }
    }
}

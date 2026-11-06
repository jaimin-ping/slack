<?php

namespace App\Providers;

use App\Models\PushSubscription;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
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
        Vite::prefetch(concurrency: 3);

        // A signed-out browser must stop showing that person's messages.
        Event::listen(Logout::class, function () {
            PushSubscription::where('session_id', session()->getId())->delete();
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(60)->by(hash('sha256', (string) $request->route('token')));
        });
    }
}

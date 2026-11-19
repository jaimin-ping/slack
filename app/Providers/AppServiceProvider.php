<?php

namespace App\Providers;

use App\Ai\Contracts\LanguageModel;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\FakeLanguageModel;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Observers\MessageObserver;
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
        // The AI provider for agents. Tests swap in FakeLanguageModel::fake().
        $this->app->bind(LanguageModel::class, fn () => match (config('ai.provider')) {
            'fake' => new FakeLanguageModel,
            default => new AnthropicProvider(config('ai.providers.anthropic')),
        });
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

        // Agents read new messages in the background. Nothing here can block or fail a message being sent.
        Message::observe(MessageObserver::class);
    }
}

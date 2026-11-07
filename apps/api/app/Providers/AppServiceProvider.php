<?php

namespace App\Providers;

use App\Contracts\LLMProvider;
use App\Services\AI\OpenAIProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LLMProvider::class, OpenAIProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn ($r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('chat', fn ($r) => Limit::perMinute(10)->by($r->user()->id));
    }
}

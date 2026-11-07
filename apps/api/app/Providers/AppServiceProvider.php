<?php

namespace App\Providers;

use App\Contracts\EmbeddingProvider;
use App\Contracts\LLMProvider;
use App\Services\AI\OpenAIProvider;
use App\Services\Memory\IntelligenceClient;
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
        $this->app->bind(EmbeddingProvider::class, IntelligenceClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn ($r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('memory', fn ($r) => Limit::perMinute(20)->by($r->user()->id));
        RateLimiter::for('repository', fn ($r) => Limit::perMinute(5)->by($r->user()->id));
        RateLimiter::for('summary', fn ($r) => Limit::perMinute(3)->by($r->user()->id));
        RateLimiter::for('chat', fn ($r) => Limit::perMinute(10)->by($r->user()->id));
    }
}

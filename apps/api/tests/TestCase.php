<?php

namespace Tests;

use App\Services\AI\ProviderAvailability;
use App\Services\AI\TokenCounter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        if (config("database.connections.{$connection}.url")) {
            throw new RuntimeException('Clear DB_URL and select a disposable test database explicitly.');
        }
        if ($connection === 'sqlite' && $database !== ':memory:') {
            throw new RuntimeException('SQLite feature tests must use :memory:.');
        }
        if ($connection !== 'sqlite' && (! is_string($database) || ! str_ends_with($database, '_test'))) {
            throw new RuntimeException('Tests require SQLite or an explicitly separate database ending in _test.');
        }
        config(['sanctum.stateful' => ['localhost']]);

        $app->bind(ProviderAvailability::class, fn () => new class extends ProviderAvailability
        {
            public function check(array $model): array
            {
                return ['available' => $model['configured'], 'error' => $model['configured'] ? null : 'not_configured', 'latency_ms' => 1];
            }
        });
        $app->bind(TokenCounter::class, fn () => new class extends TokenCounter
        {
            public function countTexts(array $texts, string $encoding): array
            {
                return array_map(fn ($text) => strlen($text), $texts);
            }
        });

        return $app;
    }
}

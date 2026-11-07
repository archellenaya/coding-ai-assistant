<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

class ProviderAvailability
{
    public function ollamaUrl(): string
    {
        $url = rtrim(config('ai.ollama_url'), '/');
        $p = parse_url($url);
        $host = $p['host'] ?? '';
        // Endpoints are server-only; permit loopback/private addresses and the Docker service name.
        $private = filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (! in_array($p['scheme'] ?? '', ['http', 'https'], true) || (! in_array($host, ['ollama', 'localhost'], true) && ! $private) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || ! in_array($p['path'] ?? '', ['', '/'], true)) {
            throw new ProviderException('invalid_local_endpoint');
        }

        return $url;
    }

    public function check(array $model): array
    {
        if (! $model['configured']) {
            return ['available' => false, 'error' => 'not_configured', 'latency_ms' => null];
        }
        $start = microtime(true);
        try {
            if ($model['provider'] === 'openai') {
                $response = Http::withToken(config('chat.api_key'))->connectTimeout(2)->timeout(4)->withoutRedirecting()->get('https://api.openai.com/v1/models/'.rawurlencode($model['id']));
                $valid = $response->successful() && $response->json('id') === $model['id'];
            } else {
                if (str_contains(strtolower($model['id']), 'cloud')) {
                    return ['available' => false, 'error' => 'cloud_model_forbidden', 'latency_ms' => null];
                }
                $response = Http::connectTimeout(2)->timeout(4)->withoutRedirecting()->post($this->ollamaUrl().'/api/show', ['model' => $model['id']]);
                $caps = $response->json('capabilities') ?? [];
                $valid = $response->successful() && is_array($caps) && in_array('completion', $caps, true) && ! $response->json('remote_host') && ! $response->json('remote_model');
            }
            $error = $valid ? null : match ($response->status()) {
                401,403 => 'authentication_failed',404 => 'model_unavailable',429 => 'quota_or_rate_limited',default => 'provider_unavailable'
            };

            $window = $model['context_window'];
            if ($model['provider'] === 'ollama' && $valid) {
                foreach (($response->json('model_info') ?? []) as $key => $value) {
                    if (str_ends_with($key, '.context_length') && is_int($value) && $value > 0) {
                        $window = min($window, $value);
                    }
                }
            }

            return ['context_window' => $window, 'available' => $valid, 'error' => $error, 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
        } catch (\Throwable) {
            return ['available' => false, 'error' => 'connection_failed', 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
        }
    }
}

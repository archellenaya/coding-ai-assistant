<?php

namespace App\Services\Memory;

use App\Contracts\EmbeddingProvider;
use Illuminate\Support\Facades\Http;

class IntelligenceClient implements EmbeddingProvider
{
    public function embed(string $text): EmbeddingResult
    {
        if (! config('memory.token')) {
            throw new \RuntimeException('Embedding service is not configured.');
        }
        // Destination is server configuration, never a URL supplied by an API user.
        $response = Http::withToken(config('memory.token'))->connectTimeout(3)->timeout(90)->withOptions(['allow_redirects' => false])->post(rtrim(config('memory.intelligence_url'), '/').'/v1/embeddings', ['texts' => [$text]]);
        if (! $response->successful()) {
            throw new \RuntimeException('Embedding service unavailable.');
        }
        $data = $response->json();

        return new EmbeddingResult($data['embeddings'][0] ?? [], $data['model'] ?? '', $data['version'] ?? '', $data['fingerprint'] ?? '', $data['pooling'] ?? '');
    }
}

<?php

namespace App\Services\Memory;

final readonly class EmbeddingResult
{
    public function __construct(public array $values, public string $model, public string $version, public string $fingerprint, public string $pooling)
    {
        if (count($values) !== config('memory.dimensions') || ! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new \RuntimeException('Invalid embedding metadata.');
        }
        $norm = 0;
        foreach ($values as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
                throw new \RuntimeException('Invalid embedding vector.');
            } $norm += $value * $value;
        }
        if ($norm <= 0 || $model !== config('memory.model') || $version !== config('memory.version') || $pooling !== config('memory.pooling')) {
            throw new \RuntimeException('Embedding profile mismatch.');
        }
    }

    public function profile(): string
    {
        return hash('sha256', implode('|', [$this->model, $this->version, count($this->values), $this->fingerprint, $this->pooling]));
    }

    public function literal(): string
    {
        return json_encode(array_values($this->values), JSON_THROW_ON_ERROR);
    }
}

<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

class TokenCounter
{
    public function countTexts(array $texts, string $encoding): array
    {
        if ($encoding === 'utf8-bytes') {
            return array_map(fn ($text) => strlen($text), $texts);
        }
        $result = [];
        $batch = [];
        $size = 0;
        foreach ($texts as $text) {
            if ($batch && (mb_strlen($text) + $size > 200000 || count($batch) >= 60)) {
                $result = array_merge($result, $this->batch($batch, $encoding));
                $batch = [];
                $size = 0;
            }
            $batch[] = $text;
            $size += mb_strlen($text);
        }

        return array_merge($result, $batch ? $this->batch($batch, $encoding) : []);
    }

    private function batch(array $texts, string $encoding): array
    {
        try {
            $r = Http::withToken(config('memory.token'))->connectTimeout(3)->timeout(15)->withoutRedirecting()->post(rtrim(config('memory.intelligence_url'), '/').'/v1/token-count', ['texts' => $texts, 'encoding' => $encoding]);
            $counts = $r->json('counts');
            if (! $r->successful() || ! is_array($counts) || count($counts) !== count($texts) || count(array_filter($counts, fn ($n) => ! is_int($n) || $n < 0))) {
                throw new \RuntimeException('Invalid counts');
            }

            return $counts;
        } catch (\Throwable) {
            throw new ProviderException('tokenizer_unavailable');
        }
    }
}

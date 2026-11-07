<?php

namespace App\Services\AI;

use App\Contracts\LLMProvider;
use Illuminate\Support\Facades\Http;

class OllamaProvider implements LLMProvider
{
    public function __construct(private ProviderAvailability $availability, private ModelCatalog $catalog) {}

    public function stream(array $messages, string $model, int $maxOutputTokens): iterable
    {
        $spec = $this->catalog->find($model);
        if ($spec['provider'] !== 'ollama' || str_contains(strtolower($model), 'cloud')) {
            throw new ProviderException('model_unavailable');
        }
        $response = Http::connectTimeout(5)->timeout(100)->withoutRedirecting()->withOptions(['stream' => true, 'read_timeout' => 30])->post($this->availability->ollamaUrl().'/api/chat', ['model' => $model, 'messages' => array_merge([['role' => 'system', 'content' => ContextBuilder::INSTRUCTIONS]], $messages), 'stream' => true, 'options' => ['num_ctx' => $spec['context_window'], 'num_predict' => $maxOutputTokens]]);
        if (! $response->successful()) {
            throw new ProviderException('provider_error');
        }
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $bytes = 0;
        try {
            while (! $body->eof()) {
                $buffer .= $body->read(1024);
                $bytes += 1024;
                if ($body->eof() && $buffer !== '' && ! str_ends_with($buffer, "\n")) {
                    $buffer .= "\n";
                }
                if (strlen($buffer) > 262144 || $bytes > 4194304) {
                    throw new ProviderException('provider_protocol_error');
                }
                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 1);
                    if (trim($line) === '') {
                        continue;
                    }
                    try {
                        $event = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new ProviderException('provider_protocol_error');
                    }
                    if (! is_array($event) || isset($event['error']) || ($event['model'] ?? $model) !== $model) {
                        throw new ProviderException('provider_error');
                    }
                    if (isset($event['message']['content']) && ! is_string($event['message']['content'])) {
                        throw new ProviderException('provider_protocol_error');
                    }
                    if (($event['message']['content'] ?? '') !== '') {
                        yield new ProviderEvent('delta', $event['message']['content']);
                    }
                    if (($event['done'] ?? false) === true) {
                        if (($event['done_reason'] ?? 'stop') !== 'stop') {
                            throw new ProviderException('output_incomplete');
                        }
                        $usage = [];
                        foreach (['prompt_eval_count' => 'input_tokens', 'eval_count' => 'output_tokens'] as $key => $field) {
                            if (is_int($event[$key] ?? null) && $event[$key] >= 0) {
                                $usage[$field] = $event[$key];
                            }
                        }
                        yield new ProviderEvent('completed', usage: $usage);

                        return;
                    }
                }
            }
            throw new ProviderException('provider_stream_interrupted');
        } finally {
            $body->close();
        }
    }
}

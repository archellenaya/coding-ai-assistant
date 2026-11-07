<?php

namespace App\Services\AI;

use App\Models\AIModel;
use App\Models\AIProvider;
use App\Models\Project;

class ModelCatalog
{
    public function models(): array
    {
        $models = config('ai.models', []);
        if (! is_array($models) || ! array_is_list($models)) {
            throw new ProviderException('invalid_model_configuration');
        }
        if (config('chat.model')) {
            array_unshift($models, ['id' => config('chat.model'), 'provider' => 'openai', 'context_window' => config('chat.context_window') ?: 8192, 'max_output_tokens' => config('chat.max_output_tokens'), 'tokenizer' => config('chat.tokenizer', 'o200k_base'), 'input_price_per_million' => config('chat.input_price_per_million'), 'output_price_per_million' => config('chat.output_price_per_million')]);
        }
        if (config('ai.ollama_model')) {
            $models[] = ['id' => config('ai.ollama_model'), 'provider' => 'ollama', 'context_window' => config('ai.ollama_context'), 'tokenizer' => 'utf8-bytes'];
        }
        $result = [];
        foreach ($models as $model) {
            if (! is_array($model) || ! in_array($model['provider'] ?? '', ['openai', 'ollama'], true) || ! is_string($model['id'] ?? null) || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,149}$~', $model['id']) || isset($result[$model['id']])) {
                throw new ProviderException('invalid_model_configuration');
            }
            $model = array_intersect_key($model, array_flip(['id', 'provider', 'context_window', 'max_output_tokens', 'tokenizer', 'capabilities', 'quality', 'latency_ms', 'input_price_per_million', 'output_price_per_million', 'cached_price_per_million']));
            $m = $model + ['context_window' => 8192, 'max_output_tokens' => 1024, 'tokenizer' => 'utf8-bytes', 'capabilities' => ['text', 'streaming'], 'quality' => 1, 'latency_ms' => 1000, 'input_price_per_million' => null, 'output_price_per_million' => null, 'cached_price_per_million' => null];
            if (! is_int($m['context_window']) || $m['context_window'] < 1024 || $m['context_window'] > 2000000 || ! is_int($m['max_output_tokens']) || $m['max_output_tokens'] < 16 || $m['max_output_tokens'] > 8192 || ! in_array($m['tokenizer'], ['utf8-bytes', 'cl100k_base', 'o200k_base'], true) || ! is_array($m['capabilities']) || ! in_array('text', $m['capabilities'], true) || ! in_array('streaming', $m['capabilities'], true)) {
                throw new ProviderException('invalid_model_configuration');
            }
            foreach (['input_price_per_million', 'output_price_per_million', 'cached_price_per_million'] as $rate) {
                if ($m[$rate] === '') {
                    $m[$rate] = null;
                }
                if ($m[$rate] !== null && (! is_numeric($m[$rate]) || ! is_finite((float) $m[$rate]) || $m[$rate] < 0 || $m[$rate] > 1000000)) {
                    throw new ProviderException('invalid_model_configuration');
                }
            }
            $m['configured'] = $m['provider'] === 'ollama' || (bool) config('chat.api_key');
            if ($m['provider'] === 'ollama') {
                foreach (['input_price_per_million', 'output_price_per_million', 'cached_price_per_million'] as $rate) {
                    $m[$rate] = 0;
                }
            }
            $provider = AIProvider::firstOrCreate(['name' => $m['provider']], ['enabled' => true]);
            $provider->update(['enabled' => true]);
            $record = AIModel::firstOrCreate(['identifier' => $m['id']], ['ai_provider_id' => $provider->id, 'active' => true, 'configuration' => $m]);
            if ($record->ai_provider_id !== $provider->id) {
                throw new ProviderException('invalid_model_configuration');
            }
            if ($record->configuration !== $m || ! $record->active) {
                $record->update(['ai_provider_id' => $provider->id, 'active' => true, 'configuration' => $m]);
            }
            $m['registry_id'] = $record->id;
            $result[$m['id']] = $m;
        }

        AIModel::where('active', true)->whereNotIn('identifier', array_keys($result))->update(['active' => false]);
        AIProvider::where('enabled', true)->whereNotIn('name', array_unique(array_column($result, 'provider')))->update(['enabled' => false]);

        return array_values($result);
    }

    public function find(string $id): array
    {
        foreach ($this->models() as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }
        throw new ProviderException('model_unavailable');
    }

    public function defaults(Project $project): array
    {
        $models = $this->models();
        $providers = array_unique(array_column($models, 'provider'));
        $mode = $project->cloud_processing_allowed && count($providers) > 1 ? 'auto' : (in_array('ollama', $providers, true) && ! $project->cloud_processing_allowed ? 'ollama' : 'openai');

        return ['provider_mode' => $mode, 'preferred_model' => null, 'allow_fallback' => false];
    }
}

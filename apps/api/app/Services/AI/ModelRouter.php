<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\ModelCall;
use App\Models\User;
use App\Services\Chat\ChatAccess;

class ModelRouter
{
    public function __construct(private ModelCatalog $catalog, private ProviderAvailability $availability, private ChatAccess $access, private TokenCounter $tokens) {}

    public function select(Conversation $conversation, User $user, string $query, ?string $requested = null, ?array $snapshot = null): array
    {
        $this->access->assertCanSend($conversation, $user);
        $settings = $snapshot ?? ['mode' => $conversation->provider_mode ?? 'openai', 'preferred_model' => $conversation->preferred_model, 'allow_fallback' => (bool) $conversation->allow_fallback];
        $mode = $settings['mode'];
        abort_if($mode === 'openai' && ! $conversation->project()->firstOrFail()->cloud_processing_allowed, 403, 'Cloud processing is disabled.');
        $preferred = $requested ?? $settings['preferred_model'];
        $models = $this->catalog->models();
        if ($preferred !== null) {
            try {
                $selected = $this->catalog->find($preferred);
            } catch (ProviderException) {
                abort(422, 'Unknown model.');
            }abort_unless($mode === 'auto' || $selected['provider'] === $mode, 422, 'Model does not match the selected provider.');
        }
        if ($mode === 'auto' && isset($selected) && $selected['provider'] === 'openai' && ! $conversation->project()->firstOrFail()->cloud_processing_allowed) {
            $preferred = null;
        }
        $allowed = array_values(array_filter($models, fn ($m) => ($mode === 'auto' || $m['provider'] === $mode) && ($m['provider'] !== 'openai' || $conversation->project()->firstOrFail()->cloud_processing_allowed)));
        abort_if($mode === 'openai' && ! $conversation->project()->firstOrFail()->cloud_processing_allowed, 403, 'Cloud processing is disabled.');
        if ($preferred !== null) {
            abort_unless(count(array_filter($allowed, fn ($m) => $m['id'] === $preferred)), 403, 'Selected model is not permitted.');
        }
        $complex = mb_strlen($query) > 2000 || preg_match('/architecture|concurren|deadlock|security|complex debugging/i', $query);
        foreach ($allowed as &$candidate) {
            $recent = ModelCall::where('project_id', $conversation->project_id)->where('user_id', $user->id)->where('provider', $candidate['provider'])->where('model', $candidate['id'])->whereIn('status', ['completed', 'failed', 'uncertain'])->latest()->limit(20)->get();
            $candidate['recent_success_rate'] = $recent->isEmpty() ? null : $recent->where('status', 'completed')->count() / $recent->count();
            if ($recent->whereNotNull('duration_ms')->count() >= 3) {
                $candidate['latency_ms'] = (int) $recent->whereNotNull('duration_ms')->avg('duration_ms');
            }
        }
        unset($candidate);
        usort($allowed, function ($a, $b) use ($preferred, $complex) {
            if ($preferred) {
                return ($b['id'] === $preferred) <=> ($a['id'] === $preferred);
            }
            if ($complex && $a['quality'] !== $b['quality']) {
                return $b['quality'] <=> $a['quality'];
            }
            if (! $complex && $a['provider'] !== $b['provider']) {
                return $a['provider'] === 'ollama' ? -1 : 1;
            }

            return (($b['recent_success_rate'] ?? 1) <=> ($a['recent_success_rate'] ?? 1)) ?: (($a['input_price_per_million'] ?? config('ai.default_input_price')) <=> ($b['input_price_per_million'] ?? config('ai.default_input_price'))) ?: (($a['latency_ms'] <=> $b['latency_ms']) ?: strcmp($a['id'], $b['id']));
        });
        if (! $allowed) {
            throw new ProviderException('no_authorized_model');
        }
        if ($snapshot && isset($snapshot['candidates'])) {
            $ids = $snapshot['candidates'];
            $allowed = array_values(array_filter($allowed, fn ($m) => in_array($m['id'], $ids, true)));
            usort($allowed, fn ($a, $b) => array_search($a['id'], $ids, true) <=> array_search($b['id'], $ids, true));
        }
        $candidates = array_column($allowed, 'id');
        $failures = [];
        foreach ($allowed as $i => $model) {
            if ($i > 0 && ($mode !== 'auto' || ! $settings['allow_fallback'])) {
                break;
            }
            $minimum = array_sum($this->tokens->countTexts([ContextBuilder::INSTRUCTIONS, $query], $model['tokenizer'])) + 24 + $model['max_output_tokens'] + max(128, config('ai.safety_tokens'));
            if ($minimum > $model['context_window']) {
                $failures[] = ['model' => $model['id'], 'error' => 'input_limit'];
                if ($mode !== 'auto' || ! $settings['allow_fallback']) {
                    throw new ProviderException('input_limit');
                }

                continue;
            }
            if (! $model['configured']) {
                $status = ['available' => false, 'error' => 'not_configured'];
            } else {
                $status = $this->availability->check($model);
            }
            if ($status['available']) {
                $model['context_window'] = min($model['context_window'], $status['context_window'] ?? $model['context_window']);

                return ['model' => $model, 'routing' => ['mode' => $mode, 'preferred_model' => $preferred, 'allow_fallback' => (bool) $settings['allow_fallback'], 'candidates' => $candidates, 'selected_provider' => $model['provider'], 'selected_model' => $model['id'], 'decision' => $mode === 'auto' ? ($i > 0 ? 'authorized_availability_fallback' : (! $conversation->project()->firstOrFail()->cloud_processing_allowed ? 'local_only_policy' : ($complex ? 'complexity_capability' : 'cost_latency_local_preference'))) : 'explicit_provider', 'unavailable' => $failures, 'probe_latency_ms' => $status['latency_ms'] ?? null]];
            }
            $failures[] = ['model' => $model['id'], 'error' => $status['error']];
        }
        throw new ProviderException('provider_unavailable');
    }

    public function validatePreferences(Conversation $conversation, array $data): void
    {
        $mode = $data['provider_mode'];
        $model = $data['preferred_model'] ?? null;
        abort_if($mode === 'openai' && ! $conversation->project()->firstOrFail()->cloud_processing_allowed, 403, 'Cloud processing is disabled.');
        if ($model) {
            try {
                $m = $this->catalog->find($model);
            } catch (ProviderException) {
                abort(422, 'Unknown model.');
            }abort_unless($mode === 'auto' || $m['provider'] === $mode, 422, 'Model does not match provider.');
            abort_if($m['provider'] === 'openai' && ! $conversation->project()->firstOrFail()->cloud_processing_allowed, 403, 'Cloud model is not permitted.');
            $status = $this->availability->check($m);
            abort_unless($status['available'], 422, 'Selected model is unavailable.');
        }
        abort_if($mode !== 'auto' && ($data['allow_fallback'] ?? false), 422, 'Fallback consent applies only to Auto mode.');
    }
}

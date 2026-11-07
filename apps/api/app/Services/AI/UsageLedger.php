<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\ModelCall;
use App\Models\Organization;
use App\Models\Project;
use App\Models\UsageEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UsageLedger
{
    public function reserve(Conversation $conversation, array $model, string $type, string $source, int $attempt, array $metrics, bool $cache = false): ModelCall
    {
        return DB::transaction(function () use ($conversation, $model, $type, $source, $attempt, $metrics, $cache) {
            // One global lock order makes user/project/organization budget reservations race-safe.
            User::whereKey($conversation->user_id)->lockForUpdate()->firstOrFail();
            $project = $conversation->project()->firstOrFail();
            Organization::whereKey($project->organization_id)->lockForUpdate()->firstOrFail();
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = ModelCall::where('source_type', $type)->where('source_id', $source)->where('attempt', $attempt)->first();
            if ($existing) {
                throw new ProviderException('inference_already_reserved');
            }
            $pricing = ['input' => $model['input_price_per_million'], 'output' => $model['output_price_per_million'], 'cached' => $model['cached_price_per_million']];
            $reserve = $cache ? 0 : $this->cost($metrics['input_tokens'] + ($metrics['safety_reserved'] ?? 512), $metrics['output_reserved'], 0, ['input' => $pricing['input'] ?? config('ai.default_input_price'), 'output' => $pricing['output'] ?? config('ai.default_output_price'), 'cached' => null]);
            foreach (['user' => [$conversation->user_id, 'user_id'], 'project' => [$project->id, 'project_id'], 'organization' => [$project->organization_id, 'organization_id']] as $scope => [$id,$column]) {
                $used = ModelCall::join('usage_ledgers as l', 'l.model_call_id', '=', 'model_calls.id')->where($column, $id)->where('model_calls.created_at', '>=', now('UTC')->startOfDay())->sum('reserved_micro_usd');
                if ($reserve > 0 && $used + $reserve > config('ai.daily_'.$scope.'_budget')) {
                    throw new ProviderException('budget_exceeded');
                }
            }

            $call = ModelCall::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'user_id' => $conversation->user_id, 'source_type' => $type, 'source_id' => $source, 'attempt' => $attempt, 'provider' => $cache ? 'cache' : $model['provider'], 'model' => $cache ? 'verified-solution' : $model['id'], 'status' => 'reserved', 'ai_model_id' => $model['registry_id'] ?? null, 'pricing' => $pricing, 'context_metrics' => $metrics, 'cache_hit' => $cache]);
            UsageEntry::create(['model_call_id' => $call->id, 'reserved_micro_usd' => $reserve]);

            return $call;
        });
    }

    public function complete(ModelCall $call, array $usage, int $duration): void
    {
        DB::transaction(function () use ($call, $usage, $duration) {
            $row = ModelCall::whereKey($call->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'reserved') {
                return;
            }
            $input = $usage['input_tokens'] ?? null;
            $output = $usage['output_tokens'] ?? null;
            $cached = $usage['cached_tokens'] ?? 0;
            foreach ([$input, $output, $cached] as $n) {
                if ($n !== null && (! is_int($n) || $n < 0)) {
                    throw new ProviderException('invalid_usage');
                }
            }
            $cost = $row->cache_hit ? 0 : ($input !== null && $output !== null && $row->pricing['input'] !== null && $row->pricing['output'] !== null ? $this->cost($input, $output, min($cached, $input), $row->pricing) : null);
            $row->update(['status' => 'completed', 'input_tokens' => $input, 'output_tokens' => $output, 'cached_tokens' => $cached, 'duration_ms' => max(0, $duration)]);
            $row->ledger->update(['estimated_micro_usd' => $cost, 'reserved_micro_usd' => $cost ?? $row->reserved_micro_usd]);
        });
    }

    public function fail(ModelCall $call, string $code, int $duration, bool $started): void
    {
        DB::transaction(function () use ($call, $code, $duration, $started) {
            $row = ModelCall::whereKey($call->id)->where('status', 'reserved')->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $row->update(['status' => $started ? 'uncertain' : 'failed', 'duration_ms' => max(0, $duration), 'error_code' => $code]);
            if (! $started) {
                $row->ledger->update(['reserved_micro_usd' => 0]);
            }
        });
    }

    private function cost(int $input, int $output, int $cached, array $rates): int
    {
        foreach (['input', 'output'] as $key) {
            $rate = $rates[$key];
            if (! is_numeric($rate) || ! is_finite((float) $rate) || $rate < 0 || $rate > 1000000) {
                throw new ProviderException('invalid_model_configuration');
            }
        }

        // USD per million tokens equals micro-USD per token. Never label estimates as invoice costs.
        return (int) ceil(($input - $cached) * (float) $rates['input'] + $cached * (float) ($rates['cached'] ?? $rates['input']) + $output * (float) $rates['output']);
    }
}

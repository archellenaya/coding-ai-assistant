<?php

namespace App\Jobs;

use App\Contracts\LLMProvider;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\ModelCall;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Solution;
use App\Models\User;
use App\Services\AI\ContextBuilder;
use App\Services\AI\ModelRouter;
use App\Services\AI\OllamaProvider;
use App\Services\AI\ProviderException;
use App\Services\AI\SolutionCache;
use App\Services\AI\UsageLedger;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatService;
use App\Services\Memory\MemoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateChatResponse implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public string $messageId, public int $attempt) {}

    public function handle(LLMProvider $provider, ChatService $chat, ChatAccess $access): void
    {
        $claimed = Message::whereKey($this->messageId)->where('attempt', $this->attempt)->where('status', 'queued')->update(['status' => 'streaming', 'started_at' => now()]);
        if (! $claimed) {
            return;
        }
        $call = null;
        $started = false;
        $clock = microtime(true);
        try {
            $message = Message::findOrFail($this->messageId);
            $conversation = $message->conversation;
            $owner = User::findOrFail($conversation->user_id);
            $access->assertCanSend($conversation, $owner);
            $prompt = $conversation->messages()->whereKey($message->reply_to_id)->firstOrFail()->content;
            $snapshot = $message->routing ?? ['mode' => $message->provider, 'preferred_model' => $message->model, 'allow_fallback' => false];
            $solution = app(SolutionCache::class)->lookup($conversation->project, $owner, $snapshot['solution_context'] ?? null);
            if ($solution) {
                $model = ['id' => 'verified-solution', 'provider' => 'cache', 'input_price_per_million' => 0, 'output_price_per_million' => 0, 'cached_price_per_million' => 0];
                $metrics = ['input_tokens' => 0, 'output_reserved' => 0, 'solution_id' => $solution->id, 'solution_revision' => $solution->revision];
                $call = app(UsageLedger::class)->reserve($conversation, $model, 'message', $message->id, $message->attempt, $metrics, true);
                DB::transaction(function () use ($solution, $conversation, $owner, $snapshot, $message, $call, $clock, $metrics) {
                    Project::whereKey($conversation->project_id)->lockForUpdate()->firstOrFail();
                    app(ChatAccess::class)->assertCanSend($conversation->fresh(), $owner);
                    $locked = Solution::whereKey($solution->id)->lockForUpdate()->firstOrFail();
                    Repository::where('project_id', $conversation->project_id)->orderBy('id')->lockForUpdate()->get();
                    $current = app(SolutionCache::class)->lookup($conversation->project, $owner, $snapshot['solution_context']);
                    if (! $current || $current->id !== $locked->id || $current->revision !== $solution->revision) {
                        throw new ProviderException('cache_invalidated');
                    }
                    $message->update(['provider' => 'cache', 'model' => 'verified-solution', 'context_metrics' => $metrics, 'routing' => $snapshot + ['cache_solution_id' => $solution->id]]);
                    if (! $this->record('delta', ['text' => $solution->content], fn ($m) => $m->update(['content' => $solution->content]))) {
                        throw new ProviderException('attempt_cancelled');
                    }
                    if (! $this->record('completed', ['usage' => []], function ($m) use ($call, $clock) {
                        app(UsageLedger::class)->complete($call, [], (int) ((microtime(true) - $clock) * 1000));
                        $m->update(['status' => 'completed', 'usage' => []]);
                    })) {
                        throw new ProviderException('attempt_cancelled');
                    }
                });

                return;
            }
            $selection = app(ModelRouter::class)->select($conversation, $owner, $prompt, null, $snapshot);
            $model = $selection['model'];
            $routing = $selection['routing'];
            if (isset($snapshot['solution_context'])) {
                $routing['solution_context'] = $snapshot['solution_context'];
            }
            $access->assertCanSend($conversation, $owner, $model['provider']);
            $context = app(ContextBuilder::class)->build($conversation, $owner, $prompt, $model);
            $message->update(['provider' => $model['provider'], 'model' => $model['id'], 'routing' => $routing, 'memory_sources' => $context['memory_sources'], 'code_sources' => $context['code_sources'], 'context_metrics' => $context['metrics']]);
            $call = app(UsageLedger::class)->reserve($conversation, $model, 'message', $message->id, $message->attempt, $context['metrics']);
            $access->assertCanSend($conversation->fresh(), $owner, $model['provider']);
            $adapter = $model['provider'] === 'ollama' ? app(OllamaProvider::class) : $provider;
            $started = true;
            $completed = false;
            foreach ($adapter->stream($context['messages'], $model['id'], $model['max_output_tokens']) as $event) {
                $access->assertCanSend($conversation->fresh(), $owner, $model['provider']);
                if ($event->type === 'delta') {
                    if (mb_strlen($message->content) + mb_strlen($event->text) > config('chat.max_response_chars')) {
                        throw new ProviderException('output_limit');
                    }
                    $message = $this->record('delta', ['text' => $event->text], fn ($m) => $m->update(['content' => $m->content.$event->text]));
                    if (! $message) {
                        throw new ProviderException('attempt_cancelled');
                    }
                } elseif ($event->type === 'completed') {
                    if ($message->content === '') {
                        throw new ProviderException('output_empty');
                    }
                    $this->record('completed', ['usage' => $event->usage], function ($m) use ($event, $call, $clock) {
                        app(UsageLedger::class)->complete($call, $event->usage, (int) ((microtime(true) - $clock) * 1000));
                        $m->update(['status' => 'completed', 'usage' => $event->usage, 'provider_response_id' => $event->responseId]);
                    });
                    $completed = true;
                    break;
                }
            }
            if (! $completed) {
                throw new ProviderException('provider_stream_interrupted');
            }
        } catch (Throwable $error) {
            $code = $error instanceof ProviderException ? $error->errorCode : 'request_failed';
            if ($call) {
                app(UsageLedger::class)->fail($call, $code, (int) ((microtime(true) - $clock) * 1000), $started);
            }
            $this->markFailed($code);
        }
    }

    private function record(string $type, array $payload, \Closure $update): ?Message
    {
        return DB::transaction(function () use ($type, $payload, $update) {
            $message = Message::whereKey($this->messageId)->lockForUpdate()->first();
            if (! $message || $message->attempt !== $this->attempt || $message->status !== 'streaming') {
                return null;
            }
            $update($message);
            if ($type === 'completed') {
                app(MemoryService::class)->capture($message);
            }
            MessageEvent::create(['message_id' => $message->id, 'attempt' => $message->attempt, 'type' => $type, 'payload' => $payload]);

            return $message;
        });
    }

    public function failed(?Throwable $error): void
    {
        ModelCall::where('source_type', 'message')->where('source_id', $this->messageId)->where('attempt', $this->attempt)->where('status', 'reserved')->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted']);
        $this->markFailed('worker_interrupted');
    }

    public function markFailed(string $code): void
    {
        $this->record('failed', ['code' => $code, 'message' => 'Response failed. Check project privacy and provider settings before retrying.'], fn ($m) => $m->update(['status' => 'failed', 'error_code' => $code]));
    }
}

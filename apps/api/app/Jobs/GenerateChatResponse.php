<?php

namespace App\Jobs;

use App\Contracts\LLMProvider;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\User;
use App\Services\AI\ProviderException;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatService;
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
        try {
            $message = Message::findOrFail($this->messageId);
            $conversation = $message->conversation;
            $access->assertCanSend($conversation, User::findOrFail($conversation->user_id));
            if ($message->model !== config('chat.model')) {
                throw new ProviderException('model_unavailable');
            }
            $input = $chat->history($conversation);
            $input[] = ['role' => 'user', 'content' => $conversation->messages()->whereKey($message->reply_to_id)->firstOrFail()->content];
            if (array_sum(array_map(fn ($m) => mb_strlen($m['content']), $input)) > config('chat.max_input_chars')) {
                throw new ProviderException('input_limit');
            }
            $completed = false;
            foreach ($provider->stream($input, $message->model, config('chat.max_output_tokens')) as $event) {
                if ($event->type === 'delta') {
                    $access->assertCanSend($conversation->fresh(), User::findOrFail($conversation->user_id));
                    if (mb_strlen($message->content) + mb_strlen($event->text) > config('chat.max_response_chars')) {
                        throw new ProviderException('output_limit');
                    }
                    $message = $this->record('delta', ['text' => $event->text], fn ($m) => $m->update(['content' => $m->content.$event->text]));
                    if (! $message) {
                        return;
                    }
                } elseif ($event->type === 'completed') {
                    if ($message->content === '') {
                        throw new ProviderException('output_empty');
                    }
                    $this->record('completed', ['usage' => $event->usage], fn ($m) => $m->update(['status' => 'completed', 'usage' => $event->usage, 'provider_response_id' => $event->responseId]));
                    $completed = true;
                    break;
                }
            }
            if (! $completed) {
                throw new ProviderException('provider_stream_interrupted');
            }
        } catch (Throwable $error) {
            $this->markFailed($error instanceof ProviderException ? $error->errorCode : 'request_failed');
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
            MessageEvent::create(['message_id' => $message->id, 'attempt' => $message->attempt, 'type' => $type, 'payload' => $payload]);

            return $message;
        });
    }

    public function failed(?Throwable $error): void
    {
        $this->markFailed('worker_interrupted');
    }

    public function markFailed(string $code): void
    {
        $this->record('failed', ['code' => $code, 'message' => 'Response failed. Check project privacy and provider settings before retrying.'], fn ($m) => $m->update(['status' => 'failed', 'error_code' => $code]));
    }
}

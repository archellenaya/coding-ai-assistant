<?php

namespace App\Services\Chat;

use App\Jobs\GenerateChatResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChatService
{
    public function __construct(private ChatAccess $access) {}

    public function send(Conversation $conversation, User $user, array $data): Message
    {
        return DB::transaction(function () use ($conversation, $user, $data) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCanSend($conversation, $user);
            $existing = $conversation->messages()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->content === $data['content'] && $data['model'] === $conversation->messages()->where('reply_to_id', $existing->id)->value('model'), 409, 'Idempotency key already used for different input.');

                return $conversation->messages()->where('reply_to_id', $existing->id)->firstOrFail();
            }
            abort_unless($data['model'] === config('chat.model'), 422, 'Unsupported model.');
            $this->assertIdle($conversation);
            abort_if($conversation->messages()->where('role', 'user')->count() >= config('chat.max_turns'), 422, 'Conversation limit reached; start a new conversation.');
            $history = $this->history($conversation);
            abort_if(mb_strlen($data['content']) + array_sum(array_map(fn ($m) => mb_strlen($m['content']), $history)) > config('chat.max_input_chars'), 422, 'Conversation exceeds the input limit; start a new conversation.');
            $sequence = ($conversation->messages()->max('sequence') ?? 0) + 1;
            $prompt = $conversation->messages()->create(['sequence' => $sequence, 'role' => 'user', 'status' => 'completed', 'content' => $data['content'], 'idempotency_key' => $data['idempotency_key']]);
            $assistant = $conversation->messages()->create(['sequence' => $sequence + 1, 'role' => 'assistant', 'status' => 'queued', 'content' => '', 'reply_to_id' => $prompt->id, 'provider' => 'openai', 'model' => $data['model']]);
            $conversation->touch();
            $this->enqueue($assistant);

            return $assistant->fresh();
        });
    }

    public function retry(Conversation $conversation, Message $message, User $user, int $attempt): Message
    {
        return DB::transaction(function () use ($conversation, $message, $user, $attempt) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCanSend($conversation, $user);
            $message = $conversation->messages()->whereKey($message->id)->firstOrFail();
            abort_unless($message->role === 'assistant', 422, 'Only assistant responses can be retried.');
            if ($message->attempt > $attempt) {
                return $message;
            }
            abort_unless($message->attempt === $attempt && $message->status === 'failed', 409, 'Response is not retryable.');
            abort_if($message->attempt >= config('chat.max_attempts'), 422, 'Retry limit reached.');
            abort_if($message->sequence !== $conversation->messages()->max('sequence'), 409, 'Only the latest response can be retried.');
            abort_unless($message->model === config('chat.model'), 422, 'Original model is no longer configured.');
            $this->assertIdle($conversation);
            $message->update(['status' => 'queued', 'content' => '', 'attempt' => $message->attempt + 1, 'usage' => null, 'provider_response_id' => null, 'error_code' => null, 'started_at' => null]);
            $this->enqueue($message);

            return $message->fresh();
        });
    }

    public function history(Conversation $conversation): array
    {
        $history = [];
        foreach ($conversation->messages()->where('role', 'assistant')->where('status', 'completed')->get() as $reply) {
            $prompt = $conversation->messages()->whereKey($reply->reply_to_id)->firstOrFail();
            $history[] = ['role' => 'user', 'content' => $prompt->content];
            $history[] = ['role' => 'assistant', 'content' => $reply->content];
        }

        return $history;
    }

    private function assertIdle(Conversation $conversation): void
    {
        abort_if($conversation->messages()->whereIn('status', ['queued', 'streaming'])->exists(), 409, 'A response is already in progress.');
    }

    private function enqueue(Message $message): void
    {
        MessageEvent::create(['message_id' => $message->id, 'attempt' => $message->attempt, 'type' => 'reset', 'payload' => ['attempt' => $message->attempt]]);
        // Database queue is a transactional outbox: no message can commit without its queued job.
        GenerateChatResponse::dispatch($message->id, $message->attempt)->onConnection('database')->onQueue('chat')->beforeCommit();
    }
}

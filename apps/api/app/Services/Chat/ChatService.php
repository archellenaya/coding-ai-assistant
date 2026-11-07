<?php

namespace App\Services\Chat;

use App\Jobs\GenerateChatResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\User;
use App\Services\AI\ModelRouter;
use Illuminate\Support\Facades\DB;

class ChatService
{
    public function __construct(private ChatAccess $access, private ModelRouter $router) {}

    public function send(Conversation $conversation, User $user, array $data): Message
    {
        return DB::transaction(function () use ($conversation, $user, $data) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCanSend($conversation, $user);
            $existing = $conversation->messages()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $reply = $conversation->messages()->where('reply_to_id', $existing->id)->firstOrFail();
                abort_unless($existing->content === $data['content'] && ($reply->request_hash ? hash_equals($reply->request_hash, $this->requestHash($data)) : (($data['model'] ?? null) === $reply->model)), 409, 'Idempotency key already used for different input.');

                return $conversation->messages()->where('reply_to_id', $existing->id)->firstOrFail();
            }
            $selection = $this->router->select($conversation, $user, $data['content'], $data['model'] ?? null);
            if (isset($data['solution_context'])) {
                $selection['routing']['solution_context'] = $data['solution_context'];
            }
            $this->assertIdle($conversation);
            abort_if($conversation->messages()->where('role', 'user')->count() >= config('chat.max_turns'), 422, 'Conversation limit reached; start a new conversation.');
            abort_if(mb_strlen($data['content']) > config('chat.max_input_chars'), 422, 'Prompt exceeds the input limit; no text was truncated.');
            $sequence = ($conversation->messages()->max('sequence') ?? 0) + 1;
            $prompt = $conversation->messages()->create(['sequence' => $sequence, 'role' => 'user', 'status' => 'completed', 'content' => $data['content'], 'idempotency_key' => $data['idempotency_key']]);
            $assistant = $conversation->messages()->create(['sequence' => $sequence + 1, 'role' => 'assistant', 'status' => 'queued', 'content' => '', 'reply_to_id' => $prompt->id, 'provider' => $selection['model']['provider'], 'model' => $selection['model']['id'], 'routing' => $selection['routing'], 'request_hash' => $this->requestHash($data)]);
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
            $selection = $this->router->select($conversation, $user, $conversation->messages()->whereKey($message->reply_to_id)->firstOrFail()->content, null, $message->routing);
            if (isset($message->routing['solution_context'])) {
                $selection['routing']['solution_context'] = $message->routing['solution_context'];
            }
            $message->provider = $selection['model']['provider'];
            $message->model = $selection['model']['id'];
            $message->routing = $selection['routing'];
            $this->assertIdle($conversation);
            $message->update(['status' => 'queued', 'content' => '', 'attempt' => $message->attempt + 1, 'usage' => null, 'provider_response_id' => null, 'error_code' => null, 'started_at' => null]);
            $this->enqueue($message);

            return $message->fresh();
        });
    }

    private function requestHash(array $data): string
    {
        return hash('sha256', json_encode([$data['content'], $data['model'] ?? null, $data['solution_context'] ?? null], JSON_THROW_ON_ERROR));
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

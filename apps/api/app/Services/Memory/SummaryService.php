<?php

namespace App\Services\Memory;

use App\Jobs\SummarizeConversation;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\User;
use App\Services\AI\ModelRouter;
use App\Services\Chat\ChatAccess;
use Illuminate\Support\Facades\DB;

class SummaryService
{
    public function __construct(private ChatAccess $access, private ModelRouter $router) {}

    public function request(Conversation $conversation, User $user, bool $retry, ?string $summaryId = null, ?int $attempt = null): ConversationSummary
    {
        return DB::transaction(function () use ($conversation, $user, $retry, $summaryId, $attempt) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCanSend($conversation, $user, $conversation->provider_mode === 'openai' ? 'openai' : null);
            abort_if($conversation->messages()->whereIn('status', ['queued', 'streaming'])->exists(), 409, 'Wait for the active response.');
            if ($retry) {
                $summary = ConversationSummary::where('conversation_id', $conversation->id)->whereKey($summaryId)->firstOrFail();
                // A repeated retry must not buy another inference even if its replacement failed quickly.
                if ($summary->attempt > $attempt) {
                    return $summary;
                }
                abort_unless($summary->attempt === $attempt && $summary->status === 'failed', 409, 'Summary is not retryable.');
                abort_if($summary->attempt >= 3, 422, 'Summary retry limit reached.');
                $sequence = $summary->through_sequence;
            } else {
                $sequence = $conversation->messages()->where('role', 'assistant')->where('status', 'completed')->max('sequence');
                abort_unless($sequence, 422, 'No completed conversation turns to summarize.');
                $summary = ConversationSummary::where('conversation_id', $conversation->id)->where('through_sequence', $sequence)->first();
                if ($summary) {
                    return $summary;
                }
            }
            $selection = $this->router->select($conversation, $user, 'Summarize conversation', null, $retry ? $summary->routing : null);
            $input = $this->input($conversation, (int) $sequence);
            abort_if(mb_strlen($input) > config('memory.summary_input_chars'), 422, 'Summary source exceeds the limit; no text was truncated.');
            if ($summary) {
                $summary->update(['status' => 'queued', 'content' => null, 'error_code' => null, 'attempt' => $summary->attempt + 1, 'model' => $selection['model']['id'], 'provider' => $selection['model']['provider'], 'routing' => $selection['routing']]);
            } else {
                $summary = ConversationSummary::create(['conversation_id' => $conversation->id, 'through_sequence' => $sequence, 'status' => 'queued', 'model' => $selection['model']['id'], 'provider' => $selection['model']['provider'], 'routing' => $selection['routing']]);
            }
            SummarizeConversation::dispatch($summary->id, $summary->attempt)->onConnection('database')->onQueue('summary')->beforeCommit();

            return $summary->fresh();
        });
    }

    public function input(Conversation $conversation, int $sequence): string
    {
        $turns = [];
        foreach ($conversation->messages()->where('role', 'assistant')->where('status', 'completed')->where('sequence', '<=', $sequence)->get() as $reply) {
            $prompt = $conversation->messages()->whereKey($reply->reply_to_id)->firstOrFail();
            $turns[] = ['user_message_id' => $prompt->id, 'user' => $prompt->content, 'assistant_message_id' => $reply->id, 'assistant' => $reply->content];
        }

        return json_encode($turns, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

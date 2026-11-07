<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\MessageEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverChatResponses extends Command
{
    protected $signature = 'chat:recover';

    protected $description = 'Fail stale chat responses without making another provider request';

    public function handle(): int
    {
        $count = 0;
        Message::whereIn('status', ['queued', 'streaming'])->where('updated_at', '<', now()->subSeconds(config('chat.stale_seconds')))->select('id')->chunkById(100, function ($messages) use (&$count) {
            foreach ($messages as $candidate) {
                DB::transaction(function () use ($candidate, &$count) {
                    $message = Message::whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $message || ! in_array($message->status, ['queued', 'streaming'], true) || $message->updated_at->gte(now()->subSeconds(config('chat.stale_seconds')))) {
                        return;
                    }
                    $message->update(['status' => 'failed', 'error_code' => 'worker_interrupted']);
                    MessageEvent::create(['message_id' => $message->id, 'attempt' => $message->attempt, 'type' => 'failed', 'payload' => ['code' => 'worker_interrupted', 'message' => 'Response interrupted. You may retry explicitly.']]);
                    $count++;
                });
            }
        });
        $this->info("Recovered {$count} stale responses.");

        return self::SUCCESS;
    }
}

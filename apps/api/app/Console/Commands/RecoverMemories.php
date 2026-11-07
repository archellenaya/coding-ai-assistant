<?php

namespace App\Console\Commands;

use App\Models\ConversationSummary;
use App\Models\Memory;
use Illuminate\Console\Command;

class RecoverMemories extends Command
{
    protected $signature = 'memory:recover';

    protected $description = 'Fail stale indexing and summary work without paid automatic retries';

    public function handle(): int
    {
        $index = Memory::whereIn('index_status', ['pending', 'indexing'])->where('updated_at', '<', now()->subMinutes(5))->update(['index_status' => 'failed', 'index_error' => 'worker_interrupted']);
        $summaries = ConversationSummary::whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'failed', 'error_code' => 'worker_interrupted']);
        $this->info("Recovered {$index} memories and {$summaries} summaries.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\Message;
use App\Services\Memory\MemoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexMemories extends Command
{
    protected $signature = 'memory:reindex {--project= : Restrict to a project UUID} {--capture : Backfill completed chat turns before indexing}';

    protected $description = 'Queue current embedding generation without mixing vector profiles';

    public function handle(MemoryService $service): int
    {
        $project = $this->option('project');
        if ($this->option('capture')) {
            Message::where('role', 'assistant')->where('status', 'completed')->when($project, fn ($q) => $q->whereHas('conversation', fn ($c) => $c->where('project_id', $project)))->orderBy('id')->chunkById(100, function ($messages) use ($service) {
                foreach ($messages as $message) {
                    DB::transaction(fn () => $service->capture($message));
                }
            });
        }
        $count = 0;
        Memory::where('state', 'active')->whereIn('index_status', ['ready', 'failed'])->when($project, fn ($q) => $q->where('project_id', $project))->chunkById(100, function ($memories) use ($service, &$count) {
            foreach ($memories as $memory) {
                $service->reindex($memory);
                $count++;
            }
        });
        $this->info("Queued {$count} existing memories for indexing.");

        return self::SUCCESS;
    }
}

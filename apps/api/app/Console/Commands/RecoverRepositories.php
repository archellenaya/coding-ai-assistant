<?php

namespace App\Console\Commands;

use App\Jobs\IndexRepository;
use App\Models\IndexingJob;
use Illuminate\Console\Command;

class RecoverRepositories extends Command
{
    protected $signature = 'repositories:recover';

    protected $description = 'Fail stale repository jobs without replaying work';

    public function handle(): int
    {
        IndexingJob::whereIn('status', ['queued', 'indexing'])->where('updated_at', '<', now()->subMinutes(5))->pluck('id')->each(fn ($id) => (new IndexRepository($id))->failed(null));

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Contracts\EmbeddingProvider;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class IndexMemory implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public string $memoryId, public int $revision, public string $token) {}

    public function handle(EmbeddingProvider $provider): void
    {
        if (! $this->query()->where('index_status', 'pending')->update(['index_status' => 'indexing'])) {
            return;
        }
        try {
            $memory = $this->query()->firstOrFail();
            $user = User::findOrFail($memory->user_id);
            Gate::forUser($user)->authorize('view', $memory);
            $result = $provider->embed($memory->content);
            DB::transaction(function () use ($result, $user) {
                $memory = $this->query()->where('index_status', 'indexing')->lockForUpdate()->first();
                if (! $memory) {
                    return;
                }
                Gate::forUser($user)->authorize('view', $memory);
                // Replace inside the transaction: changing dimensions cannot leave the old vector under new metadata.
                DB::table('memory_embeddings')->where('memory_id', $memory->id)->delete();
                DB::table('memory_embeddings')->insert(['memory_id' => $memory->id] + [
                    'revision' => $memory->revision, 'profile' => $result->profile(), 'model' => $result->model, 'model_version' => $result->version,
                    'dimensions' => count($result->values), 'artifact_fingerprint' => $result->fingerprint, 'pooling' => $result->pooling,
                    'values' => $result->literal(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if (DB::getDriverName() === 'pgsql') {
                    DB::update('UPDATE memory_embeddings SET embedding = ?::vector WHERE memory_id = ?', [$result->literal(), $memory->id]);
                }
                $memory->update(['index_status' => 'ready', 'index_error' => null]);
            });
        } catch (Throwable) {
            $this->failed(null);
        }
    }

    private function query()
    {
        return Memory::whereKey($this->memoryId)->where('revision', $this->revision)->where('index_token', $this->token)->where('state', 'active');
    }

    public function failed(?Throwable $error): void
    {
        $this->query()->whereIn('index_status', ['pending', 'indexing'])->update(['index_status' => 'failed', 'index_error' => 'indexing_failed']);
    }
}

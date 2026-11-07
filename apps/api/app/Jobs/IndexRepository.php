<?php

namespace App\Jobs;

use App\Contracts\EmbeddingProvider;
use App\Models\CodeChunk;
use App\Models\IndexingJob;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Models\User;
use App\Services\Repository\RepositoryClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class IndexRepository implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public string $jobId) {}

    public function handle(RepositoryClient $client, EmbeddingProvider $embeddings): void
    {
        if (! IndexingJob::whereKey($this->jobId)->where('status', 'queued')->update(['status' => 'indexing'])) {
            return;
        }
        try {
            $job = IndexingJob::findOrFail($this->jobId);
            $repo = Repository::findOrFail($job->repository_id);
            $user = User::findOrFail($job->user_id);
            Gate::forUser($user)->authorize('update', $repo->project);
            if ($repo->project->archived_at) {
                throw new \RuntimeException('Archived');
            }
            $snapshot = $client->snapshot($repo->path, $repo->project_id);
            // A current probe identifies the entire embedding generation even when no file changed.
            $profile = $embeddings->embed('Repository embedding profile');
            $prepared = [];
            $stats = ['changed' => 0, 'unchanged' => 0, 'deleted' => 0, 'files' => count($snapshot['files'])];
            foreach ($snapshot['files'] as $file) {
                $previous = RepositoryFile::where('repository_id', $repo->id)->where('path', $file['path'])->first();
                $reuse = $previous && $previous->content_hash === $file['hash'] && $repo->parser_version === $snapshot['parser_version'] && $repo->profile === $profile->profile();
                if ($reuse) {
                    $stats['unchanged']++;
                } else {
                    $stats['changed']++;
                    foreach ($file['chunks'] as &$chunk) {
                        $chunk['embedding'] = $embeddings->embed('File: '.$file['path']."\nSymbol: ".($chunk['symbol'] ?? '')."\n".$chunk['content']);
                        if ($chunk['embedding']->profile() !== $profile->profile()) {
                            throw new \RuntimeException('Embedding generation changed');
                        }
                    }
                    unset($chunk);
                }
                $prepared[] = ['file' => $file, 'reuse' => $reuse];
            }
            DB::transaction(function () use ($job, $repo, $user, $snapshot, $prepared, $profile, $stats) {
                $current = Repository::whereKey($repo->id)->lockForUpdate()->first();
                $work = IndexingJob::whereKey($job->id)->where('status', 'indexing')->lockForUpdate()->first();
                if (! $current || ! $work) {
                    return;
                }
                Gate::forUser($user)->authorize('update', $current->project);
                if ($current->project->archived_at) {
                    throw new \RuntimeException('Archived');
                }
                $paths = array_column($snapshot['files'], 'path');
                $deleted = RepositoryFile::where('repository_id', $repo->id)->whereNotIn('path', $paths)->get();
                $stats['deleted'] = $deleted->count();
                foreach ($deleted as $file) {
                    $file->delete();
                }
                foreach ($prepared as $item) {
                    $file = $item['file'];
                    if ($item['reuse']) {
                        continue;
                    }
                    $record = RepositoryFile::updateOrCreate(['repository_id' => $repo->id, 'path' => $file['path']], ['language' => $file['language'], 'parser' => $file['parser'], 'content_hash' => $file['hash']]);
                    CodeChunk::where('repository_file_id', $record->id)->delete();
                    foreach ($file['chunks'] as $ordinal => $chunk) {
                        $e = $chunk['embedding'];
                        $row = CodeChunk::create(['repository_file_id' => $record->id, 'ordinal' => $ordinal, 'content' => $chunk['content'], 'symbol' => $chunk['symbol'], 'start_line' => $chunk['start_line'], 'end_line' => $chunk['end_line'], 'profile' => $e->profile(), 'model' => $e->model, 'model_version' => $e->version, 'dimensions' => count($e->values), 'artifact_fingerprint' => $e->fingerprint, 'pooling' => $e->pooling, 'values' => $e->values]);
                        if (DB::getDriverName() === 'pgsql') {
                            DB::update('UPDATE code_chunks SET embedding = ?::vector WHERE id = ?', [$e->literal(), $row->id]);
                        }
                    }
                }
                $current->update(['revision' => $snapshot['revision'], 'parser_version' => $snapshot['parser_version'], 'profile' => $profile->profile(), 'languages' => $snapshot['languages'], 'frameworks' => $snapshot['frameworks']]);
                $work->update(['status' => 'ready', 'stats' => $stats]);
            });
        } catch (Throwable) {
            $this->failed(null);
        }
    }

    public function failed(?Throwable $error): void
    {
        IndexingJob::whereKey($this->jobId)->whereIn('status', ['queued', 'indexing'])->update(['status' => 'failed', 'error_code' => 'indexing_failed']);
    }
}

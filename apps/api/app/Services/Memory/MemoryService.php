<?php

namespace App\Services\Memory;

use App\Jobs\IndexMemory;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class MemoryService
{
    public function create(Project $project, User $user, array $data): Memory
    {
        Gate::forUser($user)->authorize('view', $project);
        abort_if($project->archived_at, 409, 'Project is archived.');

        return DB::transaction(function () use ($project, $user, $data) {
            // Serialize manual deduplication on the project; no user may forge source provenance.
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $data['content']);
            $key = 'manual:'.$data['category'].':'.$hash;
            $existing = Memory::where('project_id', $project->id)->where('user_id', $user->id)->where('source_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $memory = Memory::create($data + ['project_id' => $project->id, 'user_id' => $user->id, 'content_hash' => $hash, 'source_kind' => 'manual', 'source_key' => $key, 'provenance' => ['actor_id' => $user->id, 'method' => 'manual']]);
            $this->queue($memory);

            return $memory->fresh();
        });
    }

    public function correct(Memory $memory, string $content, int $revision): Memory
    {
        return DB::transaction(function () use ($memory, $content, $revision) {
            $memory = Memory::whereKey($memory->id)->lockForUpdate()->firstOrFail();
            abort_unless($memory->revision === $revision, 409, 'Memory changed; reload before correcting.');
            $hash = hash('sha256', $content);
            if ($memory->source_kind === 'manual') {
                $key = 'manual:'.$memory->category.':'.$hash;
                abort_if(Memory::where('project_id', $memory->project_id)->where('user_id', $memory->user_id)->where('source_key', $key)->where('id', '!=', $memory->id)->exists(), 409, 'An identical memory already exists.');
                $memory->source_key = $key;
            }
            $provenance = $memory->provenance;
            $provenance['last_correction'] = ['actor_id' => $memory->user_id, 'previous_hash' => $memory->content_hash, 'revision' => $memory->revision + 1, 'at' => now()->toIso8601String()];
            $memory->fill(['content' => $content, 'content_hash' => $hash, 'provenance' => $provenance, 'revision' => $memory->revision + 1, 'index_error' => null, 'index_status' => 'none', 'index_token' => null])->save();
            DB::table('memory_embeddings')->where('memory_id', $memory->id)->delete();
            if ($memory->state === 'active') {
                $this->queue($memory);
            }

            return $memory->fresh();
        });
    }

    public function invalidate(Memory $memory): Memory
    {
        return DB::transaction(function () use ($memory) {
            $memory = Memory::whereKey($memory->id)->lockForUpdate()->firstOrFail();
            if ($memory->state === 'invalidated') {
                return $memory;
            }
            $memory->update(['state' => 'invalidated', 'revision' => $memory->revision + 1, 'index_status' => 'none', 'index_token' => null]);
            DB::table('memory_embeddings')->where('memory_id', $memory->id)->delete();

            return $memory;
        });
    }

    public function reindex(Memory $memory): Memory
    {
        return DB::transaction(function () use ($memory) {
            $memory = Memory::whereKey($memory->id)->lockForUpdate()->firstOrFail();
            abort_unless($memory->state === 'active', 409, 'Invalidated memories cannot be indexed.');
            if (! in_array($memory->index_status, ['pending', 'indexing'], true)) {
                $this->queue($memory);
            }

            return $memory->fresh();
        });
    }

    public function capture(Message $message): void
    {
        $conversation = $message->conversation;
        $prompt = $conversation->messages()->whereKey($message->reply_to_id)->firstOrFail();
        $this->source([
            'project_id' => $conversation->project_id, 'user_id' => $conversation->user_id, 'conversation_id' => $conversation->id,
            'source_message_id' => $message->id, 'category' => 'conversation', 'source_kind' => 'conversation', 'source_key' => 'turn:'.$message->id,
            'content' => "User:\n".$prompt->content."\nAssistant:\n".$message->content,
            'provenance' => ['conversation_id' => $conversation->id, 'user_message_id' => $prompt->id, 'assistant_message_id' => $message->id, 'model' => $message->model, 'attempt' => $message->attempt],
        ]);
    }

    public function captureSummary(ConversationSummary $summary): void
    {
        $conversation = $summary->conversation;
        $this->source(['project_id' => $conversation->project_id, 'user_id' => $conversation->user_id, 'conversation_id' => $conversation->id, 'summary_id' => $summary->id,
            'category' => 'conversation', 'source_kind' => 'summary', 'source_key' => 'summary:'.$summary->id, 'content' => $summary->content,
            'provenance' => ['summary_id' => $summary->id, 'conversation_id' => $conversation->id, 'through_sequence' => $summary->through_sequence, 'model' => $summary->model, 'attempt' => $summary->attempt]]);
    }

    private function source(array $data): void
    {
        if (Memory::where('project_id', $data['project_id'])->where('user_id', $data['user_id'])->where('source_key', $data['source_key'])->exists()) {
            return;
        }
        $memory = Memory::create($data + ['content_hash' => hash('sha256', $data['content'])]);
        $this->queue($memory);
    }

    private function queue(Memory $memory): void
    {
        $token = (string) Str::uuid();
        $memory->update(['index_status' => 'pending', 'index_token' => $token, 'index_error' => null]);
        IndexMemory::dispatch($memory->id, $memory->revision, $token)->onConnection('database')->onQueue('memory')->beforeCommit();
    }
}

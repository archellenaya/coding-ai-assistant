<?php

namespace App\Services\Memory;

use App\Contracts\EmbeddingProvider;
use App\Models\Memory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class MemorySearch
{
    public function __construct(private EmbeddingProvider $provider) {}

    public function search(Project $project, User $user, string $query, int $limit = 5, ?string $excludeConversation = null): array
    {
        Gate::forUser($user)->authorize('view', $project);
        $base = Memory::where('project_id', $project->id)->where('user_id', $user->id)->where('state', 'active');
        if ($excludeConversation) {
            $base->where(fn ($q) => $q->whereNull('conversation_id')->orWhere('conversation_id', '!=', $excludeConversation));
        }
        if (! (clone $base)->exists()) {
            return ['data' => [], 'retrieval' => ['mode' => 'empty', 'degraded' => false]];
        }
        $ranks = [];
        $records = [];
        $degraded = false;
        $vectorAvailable = false;
        if (DB::getDriverName() === 'pgsql') {
            $lexical = (clone $base)->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$query])->orderByRaw("ts_rank_cd(search_vector, websearch_to_tsquery('simple', ?)) DESC", [$query])->orderBy('id')->limit(30)->get();
        } else {
            // SQLite is for fast tests/local fallback; production full-text search uses PostgreSQL.
            $terms = array_filter(preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query)));
            $lexical = (clone $base)->get()->filter(fn ($m) => count(array_filter($terms, fn ($t) => str_contains(mb_strtolower($m->content), $t))) > 0)->sortByDesc(fn ($m) => count(array_filter($terms, fn ($t) => str_contains(mb_strtolower($m->content), $t))))->take(30)->values();
        }
        $this->rank($lexical, $ranks, $records);
        if ((clone $base)->where('index_status', 'ready')->exists()) {
            try {
                $embedding = $this->provider->embed($query);
                $vectors = (clone $base)->where('index_status', 'ready')->join('memory_embeddings as e', 'memories.id', '=', 'e.memory_id')->whereColumn('e.revision', 'memories.revision')->where('e.profile', $embedding->profile())->where('e.dimensions', count($embedding->values));
                if (DB::getDriverName() === 'pgsql') {
                    // CASE guarantees that the planner cannot evaluate distance on an incompatible generation/dimension.
                    $distance = 'CASE WHEN e.profile = ? AND e.dimensions = ? AND e.revision = memories.revision THEN e.embedding <=> ?::vector END';
                    $bindings = [$embedding->profile(), count($embedding->values), $embedding->literal()];
                    $vectors = $vectors->whereNotNull('e.embedding')->whereRaw('(1 - ('.$distance.')) >= ?', [...$bindings, config('memory.min_similarity')])->orderByRaw($distance.' ASC', $bindings)->orderBy('memories.id')->select('memories.*')->limit(30)->get();
                } else {
                    $vectors = $vectors->select('memories.*', 'e.values as vector_values')->get()->map(function ($m) use ($embedding) {
                        $m->similarity = $this->cosine($embedding->values, json_decode($m->vector_values, true));

                        return $m;
                    })->filter(fn ($m) => $m->similarity >= config('memory.min_similarity'))->sortByDesc('similarity')->take(30)->values();
                }
                $vectorAvailable = true;
                $this->rank($vectors, $ranks, $records);
            } catch (Throwable) {
                $degraded = true;
            }
        }
        $exact = (clone $base)->where('error_signature', $query)->orderBy('id')->limit(30)->get();
        $this->rank($exact, $ranks, $records, 2);
        arsort($ranks);
        $results = [];
        $seen = [];
        foreach ($ranks as $id => $score) {
            $memory = $records[$id];
            $dedup = $memory->conversation_id ? 'conversation:'.$memory->conversation_id : $memory->content_hash;
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;
            // Refetch through authorization filters: concurrent invalidation/deletion is not a valid hit.
            $current = (clone $base)->whereKey($id)->where('revision', $memory->revision)->first();
            if (! $current) {
                continue;
            }
            $results[] = ['memory' => $current, 'score' => $score];
            if (count($results) >= $limit) {
                break;
            }
        }

        return ['data' => $results, 'retrieval' => ['mode' => $vectorAvailable ? 'hybrid' : 'lexical', 'degraded' => $degraded]];
    }

    private function rank($rows, array &$ranks, array &$records, int $weight = 1): void
    {
        foreach ($rows as $index => $row) {
            $ranks[$row->id] = ($ranks[$row->id] ?? 0) + $weight / (60 + $index + 1);
            $records[$row->id] = $row;
        }
    }

    private function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return -1;
        }
        $dot = 0;
        $aa = 0;
        $bb = 0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
            $aa += $v * $v;
            $bb += $b[$i] * $b[$i];
        }

        return $aa > 0 && $bb > 0 ? $dot / sqrt($aa * $bb) : -1;
    }
}

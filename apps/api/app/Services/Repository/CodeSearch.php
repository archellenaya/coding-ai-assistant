<?php

namespace App\Services\Repository;

use App\Contracts\EmbeddingProvider;
use App\Models\CodeChunk;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CodeSearch
{
    public function __construct(private EmbeddingProvider $embeddings) {}

    public function search(Project $project, User $user, string $query, int $limit = 5): array
    {
        Gate::forUser($user)->authorize('view', $project);
        $base = CodeChunk::join('repository_files as f', 'f.id', '=', 'code_chunks.repository_file_id')->join('repositories as r', 'r.id', '=', 'f.repository_id')->where('r.project_id', $project->id);
        $columns = ['code_chunks.*', 'f.path', 'f.language', 'f.content_hash', 'r.id as repository_id', 'r.revision'];
        if (! (clone $base)->exists()) {
            return ['data' => [], 'retrieval' => ['mode' => 'empty', 'degraded' => false]];
        }
        $ranks = [];
        $records = [];
        $degraded = false;
        $vector = false;
        $rank = function ($rows, $weight = 1) use (&$ranks, &$records) {
            foreach ($rows as $i => $row) {
                $ranks[$row->id] = ($ranks[$row->id] ?? 0) + $weight / (61 + $i);
                $records[$row->id] = $row;
            }
        };
        if (DB::getDriverName() === 'pgsql') {
            $lexical = (clone $base)->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$query])->orderByRaw("ts_rank_cd(search_vector, websearch_to_tsquery('simple', ?)) DESC", [$query])->orderBy('code_chunks.id')->select($columns)->limit(30)->get();
        } else {
            $terms = array_filter(preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query)));
            $lexical = (clone $base)->select($columns)->get()->filter(fn ($r) => count(array_filter($terms, fn ($t) => str_contains(mb_strtolower($r->content.' '.$r->symbol), $t))) > 0)->take(30)->values();
        }
        $rank($lexical);
        $rank((clone $base)->where('symbol', $query)->select($columns)->orderBy('code_chunks.id')->limit(30)->get(), 2);
        try {
            $e = $this->embeddings->embed($query);
            $matching = (clone $base)->where('code_chunks.profile', $e->profile())->where('dimensions', count($e->values));
            if (DB::getDriverName() === 'pgsql') {
                $distance = 'CASE WHEN code_chunks.profile = ? AND dimensions = ? THEN embedding <=> ?::vector END';
                $params = [$e->profile(), count($e->values), $e->literal()];
                $hits = $matching->whereNotNull('embedding')->whereRaw('(1 - ('.$distance.')) >= ?', [...$params, config('memory.min_similarity')])->orderByRaw($distance.' ASC', $params)->orderBy('code_chunks.id')->select($columns)->limit(30)->get();
            } else {
                $hits = $matching->select($columns)->get()->map(function ($r) use ($e) {
                    $a = $e->values;
                    $b = $r->values;
                    $dot = $aa = $bb = 0;
                    foreach ($a as $i => $v) {
                        $dot += $v * $b[$i];
                        $aa += $v * $v;
                        $bb += $b[$i] * $b[$i];
                    }
                    $r->similarity = $aa > 0 && $bb > 0 ? $dot / sqrt($aa * $bb) : -1;

                    return $r;
                })->filter(fn ($r) => $r->similarity >= config('memory.min_similarity'))->sortByDesc('similarity')->take(30)->values();
            }
            $vector = true;
            $rank($hits);
        } catch (Throwable) {
            $degraded = true;
        }
        arsort($ranks);
        $results = [];
        $seen = [];
        Gate::forUser($user)->authorize('view', $project);
        foreach ($ranks as $id => $score) {
            $record = $records[$id];
            // Resolve again against the published snapshot; changed/deleted chunks cannot be returned.
            $current = (clone $base)->where('code_chunks.id', $id)->where('r.revision', $record->revision)->where('f.content_hash', $record->content_hash)->select($columns)->first();
            if (! $current || isset($seen[hash('sha256', $current->content)])) {
                continue;
            }
            $seen[hash('sha256', $current->content)] = true;
            $results[] = ['chunk' => $current, 'score' => $score];
            if (count($results) >= $limit) {
                break;
            }
        }

        return ['data' => $results, 'retrieval' => ['mode' => $vector ? 'hybrid' : 'lexical', 'degraded' => $degraded]];
    }
}

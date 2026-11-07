<?php

namespace App\Services\Repository;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class RepositoryClient
{
    public function snapshot(string $path, string $projectId): array
    {
        $response = Http::withToken(config('memory.token'))->timeout(60)->connectTimeout(3)->withoutRedirecting()->post(rtrim(config('memory.intelligence_url'), '/').'/v1/repositories/snapshot', ['path' => $path, 'project_id' => $projectId]);
        if (! $response->successful()) {
            throw new \RuntimeException('Repository scan failed');
        }
        $data = $response->json();
        $validator = Validator::make(is_array($data) ? $data : [], [
            'revision' => 'required|string|regex:/^[a-f0-9]{40,64}$/',
            'parser_version' => 'required|string|max:100',
            'languages' => 'present|array|max:30', 'languages.*' => 'string|max:30',
            'frameworks' => 'present|array|max:30', 'frameworks.*' => 'string|max:100',
            'files' => 'present|array|max:200',
            'files.*.path' => 'required|string|max:500|distinct',
            'files.*.hash' => 'required|string|regex:/^[a-f0-9]{64}$/',
            'files.*.language' => 'required|string|max:30',
            'files.*.parser' => 'required|in:tree-sitter,text',
            'files.*.chunks' => 'present|array|max:500',
            'files.*.chunks.*.content' => 'present|string|max:3000',
            'files.*.chunks.*.symbol' => 'present|nullable|string|max:500',
            'files.*.chunks.*.start_line' => 'required|integer|min:1',
            'files.*.chunks.*.end_line' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            throw new \RuntimeException('Invalid repository snapshot');
        }
        $total = $chunks = 0;
        foreach ($data['files'] as $file) {
            foreach (explode('/', $file['path']) as $part) {
                if ($part === '' || str_starts_with($part, '.') || preg_match('/[\\\\\x00-\x1f]/', $part)) {
                    throw new \RuntimeException('Unsafe source reference');
                }
            }
            $content = implode('', array_column($file['chunks'], 'content'));
            $total += strlen($content);
            $chunks += count($file['chunks']);
            if (! hash_equals($file['hash'], hash('sha256', $content)) || $total > 1048576 || $chunks > 500) {
                throw new \RuntimeException('Invalid source content');
            }
            foreach ($file['chunks'] as $chunk) {
                if ($chunk['end_line'] < $chunk['start_line']) {
                    throw new \RuntimeException('Invalid source lines');
                }
            }
        }

        return $data;
    }
}

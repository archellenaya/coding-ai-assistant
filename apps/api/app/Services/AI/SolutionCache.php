<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SolutionCache
{
    public function digest(Project $project): string
    {
        $repos = Repository::where('project_id', $project->id)->orderBy('id')->get(['id', 'revision']);
        $files = RepositoryFile::whereIn('repository_id', $repos->pluck('id'))->orderBy('repository_id')->orderBy('path')->get(['repository_id', 'path', 'content_hash']);

        return hash('sha256', json_encode([$repos->toArray(), $files->toArray()], JSON_THROW_ON_ERROR));
    }

    public function key(array $compatibility): string
    {
        ksort($compatibility);
        if (isset($compatibility['dependencies'])) {
            ksort($compatibility['dependencies']);
        }

        return hash('sha256', json_encode($compatibility, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function lookup(Project $project, User $user, ?array $context): ?Solution
    {
        Gate::forUser($user)->authorize('view', $project);
        if (! $context) {
            return null;
        }
        $compatibility = $context['compatibility'];
        $compatibility['repository_digest'] = $this->digest($project);

        return Solution::where('project_id', $project->id)->where('user_id', $user->id)->where('state', 'active')->where('error_signature', $context['error_signature'])->where('compatibility_hash', $this->key($compatibility))->get()->first(fn ($s) => ($s->evidence['kind'] ?? null) === 'manual_test_evidence' && ($s->evidence['attested_by'] ?? null) === $user->id && ($s->evidence['exit_code'] ?? null) === 0 && ($s->evidence['attestation'] ?? false) === true);
    }

    public function create(Project $project, User $user, array $data): Solution
    {
        Gate::forUser($user)->authorize('update', $project);
        abort_if($project->archived_at, 409, 'Project is archived.');
        $compatibility = $data['compatibility'];
        $compatibility['repository_digest'] = $this->digest($project);
        $data['evidence']['exit_code'] = 0;
        $data['evidence']['attestation'] = true;
        $evidence = $data['evidence'] + ['kind' => 'manual_test_evidence', 'attested_by' => $user->id, 'attested_at' => now()->toIso8601String()];

        return Solution::create(['project_id' => $project->id, 'user_id' => $user->id, 'error_signature' => $data['error_signature'], 'content' => $data['content'], 'compatibility' => $compatibility, 'compatibility_hash' => $this->key($compatibility), 'evidence' => $evidence])->fresh();
    }

    public static function rules(string $prefix = ''): array
    {
        return [$prefix.'error_signature' => 'required|string|max:180', $prefix.'compatibility' => 'required|array:framework,framework_version,dependencies,environment,git_commit', $prefix.'compatibility.framework' => 'required|string|max:100', $prefix.'compatibility.framework_version' => 'required|string|max:100', $prefix.'compatibility.dependencies' => ['present', 'array', 'max:100', function ($attribute, $value, $fail) {
            if (! is_array($value)) {
                return;
            }
            foreach (array_keys($value) as $key) {
                if (! is_string($key) || ! preg_match('~^[A-Za-z0-9@_.:/-]{1,150}$~', $key)) {
                    $fail('Dependency names must be bounded package identifiers.');
                    break;
                }
            }
        }], $prefix.'compatibility.dependencies.*' => 'string|max:100', $prefix.'compatibility.environment' => 'required|string|max:500', $prefix.'compatibility.git_commit' => 'present|nullable|string|regex:/^[a-f0-9]{40,64}$/'];
    }
}

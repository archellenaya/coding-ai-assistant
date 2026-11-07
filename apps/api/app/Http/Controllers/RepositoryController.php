<?php

namespace App\Http\Controllers;

use App\Jobs\IndexRepository;
use App\Models\CodeChunk;
use App\Models\IndexingJob;
use App\Models\Project;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Services\Repository\CodeSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RepositoryController extends Controller
{
    public function index(Project $project)
    {
        Gate::authorize('view', $project);

        return Repository::where('project_id', $project->id)->orderBy('id')->paginate(20);
    }

    public function store(Request $request, Project $project)
    {
        Gate::authorize('update', $project);
        $data = $request->validate(['name' => 'required|string|max:150', 'path' => ['required', 'string', 'max:240', 'regex:~^[A-Za-z0-9_ -][A-Za-z0-9_. /-]*$~']]);
        foreach (explode('/', $data['path']) as $part) {
            abort_if($part === '' || str_starts_with($part, '.'), 422, 'Use a relative directory within the configured repository root.');
        }

        return DB::transaction(function () use ($project, $data) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_if($project->archived_at, 409, 'Project is archived.');
            abort_if(Repository::where('project_id', $project->id)->where('path', $data['path'])->exists(), 409, 'Repository already registered.');
            abort_if(Repository::where('project_id', $project->id)->count() >= 10, 409, 'Repository limit reached.');

            return response()->json(['data' => Repository::create($data + ['project_id' => $project->id])], 201);
        });
    }

    public function show(Repository $repository)
    {
        Gate::authorize('view', $repository->project);

        return response()->json(['data' => $repository, 'job' => IndexingJob::where('repository_id', $repository->id)->latest('created_at')->orderByDesc('id')->first()]);
    }

    public function files(Repository $repository)
    {
        Gate::authorize('view', $repository->project);

        return RepositoryFile::where('repository_id', $repository->id)->orderBy('path')->paginate(20);
    }

    public function chunk(CodeChunk $codeChunk)
    {
        $file = RepositoryFile::findOrFail($codeChunk->repository_file_id);
        $repo = Repository::findOrFail($file->repository_id);
        Gate::authorize('view', $repo->project);

        return response()->json(['data' => $codeChunk, 'file' => $file, 'repository' => $repo]);
    }

    public function enqueue(Request $request, Repository $repository)
    {
        Gate::authorize('update', $repository->project);

        return DB::transaction(function () use ($request, $repository) {
            $repo = Repository::whereKey($repository->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $repo->project);
            abort_if($repo->project->archived_at, 409, 'Project is archived.');
            $job = IndexingJob::where('repository_id', $repo->id)->whereIn('status', ['queued', 'indexing'])->first();
            if (! $job) {
                $job = IndexingJob::create(['repository_id' => $repo->id, 'user_id' => $request->user()->id, 'status' => 'queued']);
                IndexRepository::dispatch($job->id)->onConnection('database')->onQueue('repository')->beforeCommit();
            }

            return response()->json(['data' => $job], 202);
        });
    }

    public function destroy(Repository $repository)
    {
        Gate::authorize('update', $repository->project);
        DB::transaction(function () use ($repository) {
            $repo = Repository::whereKey($repository->id)->lockForUpdate()->firstOrFail();
            $repo->delete();
        });

        return response()->noContent();
    }

    public function search(Request $request, Project $project, CodeSearch $search)
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['query' => 'required|string|max:2000']);

        return response()->json($search->search($project, $request->user(), $data['query']));
    }
}

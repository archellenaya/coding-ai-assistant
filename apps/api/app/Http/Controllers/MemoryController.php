<?php

namespace App\Http\Controllers;

use App\Models\Memory;
use App\Models\Project;
use App\Services\Memory\MemorySearch;
use App\Services\Memory\MemoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MemoryController extends Controller
{
    public function index(Request $request, Project $project)
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['category' => 'nullable|in:conversation,project,error,solution', 'state' => 'nullable|in:active,invalidated']);

        return Memory::where('project_id', $project->id)->where('user_id', $request->user()->id)->when($data['category'] ?? null, fn ($q, $v) => $q->where('category', $v))->when($data['state'] ?? null, fn ($q, $v) => $q->where('state', $v))->orderByDesc('created_at')->orderBy('id')->paginate(20);
    }

    public function store(Request $request, Project $project, MemoryService $service)
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['category' => 'required|in:conversation,project,error,solution', 'content' => 'required|string|max:12000', 'error_signature' => 'nullable|string|max:180', 'git_commit' => 'nullable|string|regex:/^[a-fA-F0-9]{7,64}$/']);

        return response()->json(['data' => $service->create($project, $request->user(), $data)], 201);
    }

    public function show(Memory $memory)
    {
        Gate::authorize('view', $memory);
        $embedding = DB::table('memory_embeddings')->where('memory_id', $memory->id)->first(['model', 'model_version', 'dimensions', 'artifact_fingerprint', 'pooling', 'profile', 'revision']);

        return response()->json(['data' => $memory, 'embedding' => $embedding]);
    }

    public function update(Request $request, Memory $memory, MemoryService $service)
    {
        Gate::authorize('update', $memory);
        $data = $request->validate(['content' => 'required|string|max:12000', 'revision' => 'required|integer|min:1']);

        return response()->json(['data' => $service->correct($memory, $data['content'], $data['revision'])]);
    }

    public function invalidate(Memory $memory, MemoryService $service)
    {
        Gate::authorize('update', $memory);

        return response()->json(['data' => $service->invalidate($memory)]);
    }

    public function reindex(Memory $memory, MemoryService $service)
    {
        Gate::authorize('update', $memory);

        return response()->json(['data' => $service->reindex($memory)], 202);
    }

    public function destroy(Memory $memory)
    {
        Gate::authorize('delete', $memory);
        $memory->delete();

        return response()->noContent();
    }

    public function search(Request $request, Project $project, MemorySearch $search)
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['query' => 'required|string|max:2000']);

        return response()->json($search->search($project,$request->user(),$data['query']));
    }
}

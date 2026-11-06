<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function organizations(Request $r)
    {
        return response()->json(['data' => DB::table('organizations')->join('organization_members', 'organizations.id', '=', 'organization_members.organization_id')->where('user_id', $r->user()->id)->select('organizations.*', 'organization_members.role')->get()]);
    }

    public function index(Request $r)
    {
        return Project::whereIn('organization_id', DB::table('organization_members')->where('user_id', $r->user()->id)->select('organization_id'))->whereIn('id', DB::table('project_members')->where('user_id', $r->user()->id)->select('project_id'))->latest()->paginate(20);
    }

    public function store(Request $r)
    {
        $data = $r->validate(['organization_id' => 'required|uuid', 'name' => 'required|string|max:150', 'description' => 'nullable|string|max:5000', 'cloud_processing_allowed' => 'sometimes|boolean']);
        abort_unless(DB::table('organization_members')->where('organization_id', $data['organization_id'])->where('user_id', $r->user()->id)->where('role', 'owner')->exists(), 403);
        $project = DB::transaction(function () use ($data, $r) {
            $p = Project::create($data);
            DB::table('project_members')->insert(['project_id' => $p->id, 'user_id' => $r->user()->id]);

            return $p;
        });

        return response()->json(['data' => $project], 201);
    }

    public function show(Project $project)
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => $project]);
    }

    public function update(Request $r, Project $project)
    {
        Gate::authorize('update', $project);
        $data = $r->validate(['name' => 'sometimes|required|string|max:150', 'description' => 'nullable|string|max:5000', 'cloud_processing_allowed' => 'sometimes|boolean', 'archived' => 'sometimes|boolean']);
        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? now() : null;
            unset($data['archived']);
        }
        $project->update($data);

        return response()->json(['data' => $project->fresh()]);
    }

    public function destroy(Project $project)
    {
        Gate::authorize('delete', $project);
        $project->delete();

        return response()->noContent();
    }
}

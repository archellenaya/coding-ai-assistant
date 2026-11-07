<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ModelCall;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Solution;
use App\Services\AI\ModelCatalog;
use App\Services\AI\ModelRouter;
use App\Services\AI\ProviderAvailability;
use App\Services\AI\SolutionCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AIController extends Controller
{
    public function providers(ModelCatalog $catalog, ProviderAvailability $availability)
    {
        $models = [];
        foreach ($catalog->models() as $model) {
            $models[] = array_merge($model, $availability->check($model));
        }

        return response()->json(['data' => $models, 'note' => 'Metadata availability does not verify usable billing/quota. OpenAI API billing is separate from ChatGPT subscriptions. Local Ollama has no per-token API fee; hardware costs remain.']);
    }

    public function preferences(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        return response()->json(['data' => ['provider_mode' => $conversation->provider_mode, 'preferred_model' => $conversation->preferred_model, 'allow_fallback' => $conversation->allow_fallback]]);
    }

    public function updatePreferences(Request $request, Conversation $conversation, ModelRouter $router)
    {
        Gate::authorize('update', $conversation);
        $data = $request->validate(['provider_mode' => 'required|in:ollama,openai,auto', 'preferred_model' => 'present|nullable|string|max:150', 'allow_fallback' => 'required|boolean']);
        $router->validatePreferences($conversation, $data);
        DB::transaction(function () use ($conversation, $data) {
            $c = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $c);
            abort_if($c->project->archived_at, 409, 'Project is archived.');
            $c->update($data);
        });

        return $this->preferences($conversation->fresh());
    }

    public function solutions(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $filters = $request->validate(['search' => 'nullable|string|max:180']);

        return Solution::where('project_id', $project->id)->where('user_id', $request->user()->id)->when($filters['search'] ?? null, fn ($q, $v) => $q->whereRaw('error_signature LIKE ? ESCAPE ?', ['%'.addcslashes($v, '%_\\').'%', '\\']))->latest()->paginate(20);
    }

    public function createSolution(Request $request, Project $project, SolutionCache $cache)
    {
        Gate::authorize('update', $project);
        $data = $request->validate(SolutionCache::rules() + ['content' => 'required|string|max:12000', 'evidence' => 'required|array:command,exit_code,result,attestation', 'evidence.command' => 'required|string|max:500', 'evidence.exit_code' => 'required|integer|in:0', 'evidence.result' => 'required|string|max:4000', 'evidence.attestation' => 'required|accepted']);

        return response()->json(['data' => $cache->create($project, $request->user(), $data)], 201);
    }

    public function invalidateSolution(Request $request, Solution $solution)
    {
        $project = Project::findOrFail($solution->project_id);
        Gate::authorize('view', $project);
        abort_unless($solution->user_id === $request->user()->id, 403);
        $solution->update(['state' => 'invalidated', 'revision' => $solution->revision + 1]);

        return response()->json(['data' => $solution]);
    }

    public function usage(Request $request, Project $project)
    {
        Gate::authorize('view', $project);
        $query = ModelCall::where('project_id', $project->id)->where('user_id', $request->user()->id);
        $all = Gate::allows('update', $project) ? ModelCall::where('project_id', $project->id) : clone $query;

        return response()->json(['data' => (clone $query)->latest()->paginate(20), 'totals' => $this->totals($all), 'scope' => Gate::allows('update', $project) ? 'project' : 'current_user', 'budgets_micro_usd' => ['user' => config('ai.daily_user_budget'), 'project' => config('ai.daily_project_budget'), 'organization' => config('ai.daily_organization_budget')], 'cost_note' => 'Estimates in micro-USD; uncertain calls retain reservations. Actual provider invoices and local hardware costs are not measured.']);
    }

    public function userUsage(Request $request)
    {
        $query = ModelCall::where('user_id', $request->user()->id);

        return response()->json(['data' => (clone $query)->latest()->paginate(20), 'totals' => $this->totals($query), 'daily_budget_micro_usd' => config('ai.daily_user_budget')]);
    }

    public function organizationUsage(Request $request, Organization $organization)
    {
        abort_unless(DB::table('organization_members')->where('organization_id', $organization->id)->where('user_id', $request->user()->id)->where('role', 'owner')->exists(), 403);

        return response()->json(['data' => $this->totals(ModelCall::where('organization_id', $organization->id)), 'daily_budget_micro_usd' => config('ai.daily_organization_budget')]);
    }

    private function totals($query): array
    {
        return (clone $query)->join('usage_ledgers as l', 'l.model_call_id', '=', 'model_calls.id')->where('model_calls.created_at', '>=', now('UTC')->startOfDay())->selectRaw('provider, model, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cached_tokens) as cached_tokens, sum(reserved_micro_usd) as accounted_micro_usd, sum(estimated_micro_usd) as estimated_micro_usd, avg(duration_ms) as average_duration_ms, sum(CASE WHEN estimated_micro_usd IS NULL THEN 1 ELSE 0 END) as unknown_cost_calls')->groupBy('provider', 'model')->get()->toArray();
    }
}

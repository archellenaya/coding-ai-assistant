<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        $actor = Auth::guard('web')->id();

        return DB::transaction(function () use ($request, $next, $actor) {
            $response = $next($request);
            if ($response->isSuccessful()) {
                $project = $request->route('project');
                DB::table('audit_events')->insert(['id' => (string) Str::uuid(), 'user_id' => $actor ?? Auth::guard('web')->id(), 'action' => $request->method().' '.$request->route()->uri(), 'project_id' => $project instanceof Project ? $project->id : null, 'created_at' => now()]);
            }

            return $response;
        });
    }
}

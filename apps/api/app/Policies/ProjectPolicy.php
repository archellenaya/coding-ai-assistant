<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return DB::table('organization_members')->where('organization_id', $project->organization_id)->where('user_id', $user->id)->exists()
         && DB::table('project_members')->where('project_id', $project->id)->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project) && DB::table('organization_members')->where('organization_id', $project->organization_id)->where('user_id', $user->id)->where('role', 'owner')->exists();
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}

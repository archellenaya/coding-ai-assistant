<?php

namespace App\Policies;

use App\Models\Memory;
use App\Models\User;

class MemoryPolicy
{
    public function view(User $user, Memory $memory): bool
    {
        return $memory->user_id === $user->id && (new ProjectPolicy)->view($user, $memory->project);
    }

    public function update(User $user, Memory $memory): bool
    {
        return $this->view($user, $memory);
    }

    public function delete(User $user, Memory $memory): bool
    {
        return $this->view($user, $memory);
    }
}

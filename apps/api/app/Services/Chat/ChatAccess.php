<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ChatAccess
{
    public function assertCanSend(Conversation $conversation, User $user): void
    {
        Gate::forUser($user)->authorize('view', $conversation);
        $project = $conversation->project()->firstOrFail();
        abort_if($project->archived_at !== null, 409, 'Archived projects cannot send messages.');
        abort_unless($project->cloud_processing_allowed, 403, 'Cloud processing is disabled for this project.');
        abort_unless(config('chat.api_key') && config('chat.model'), 503, 'Configure a cloud model and API key on the server.');
    }
}

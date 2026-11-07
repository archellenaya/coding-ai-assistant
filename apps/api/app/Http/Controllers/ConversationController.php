<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\Project;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(Request $request, Project $project)
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['search' => 'sometimes|nullable|string|max:150']);

        return Conversation::where('project_id', $project->id)->where('user_id', $request->user()->id)
            ->when($data['search'] ?? null, fn ($q, $search) => $q->whereRaw('title LIKE ? ESCAPE ?', ['%'.addcslashes($search, '%_\\').'%', '\\']))->orderByDesc('updated_at')->orderBy('id')->paginate(20);
    }

    public function store(Request $request, Project $project)
    {
        Gate::authorize('view', $project);
        abort_if($project->archived_at, 409, 'Project is archived.');
        $data = $request->validate(['title' => 'required|string|max:150']);

        return response()->json(['data' => Conversation::create($data + ['project_id' => $project->id, 'user_id' => $request->user()->id])], 201);
    }

    public function show(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        return response()->json(['data' => $conversation]);
    }

    public function messages(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        return $conversation->messages()->paginate(50);
    }

    public function update(Request $request, Conversation $conversation)
    {
        Gate::authorize('update', $conversation);
        $conversation->update($request->validate(['title' => 'required|string|max:150']));

        return response()->json(['data' => $conversation]);
    }

    public function destroy(Conversation $conversation)
    {
        Gate::authorize('delete', $conversation);
        DB::transaction(function () use ($conversation) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            abort_if($conversation->messages()->whereIn('status', ['queued', 'streaming'])->exists(), 409, 'Wait for the active response before deleting.');
            $conversation->delete();
        });

        return response()->noContent();
    }

    public function send(Request $request, Conversation $conversation, ChatService $chat)
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate(['content' => 'required|string|max:12000', 'model' => 'required|string|max:150', 'idempotency_key' => 'required|uuid']);

        return response()->json(['data' => $chat->send($conversation, $request->user(), $data)], 202);
    }

    public function retry(Request $request, Conversation $conversation, Message $message, ChatService $chat)
    {
        Gate::authorize('view', $conversation);
        abort_unless($message->conversation_id === $conversation->id, 404);
        $data = $request->validate(['attempt' => 'required|integer|min:1']);

        return response()->json(['data' => $chat->retry($conversation, $message, $request->user(), $data['attempt'])], 202);
    }

    public function stream(Request $request, Conversation $conversation, Message $message)
    {
        Gate::authorize('view', $conversation);
        abort_unless($message->conversation_id === $conversation->id && $message->role === 'assistant', 404);
        $last = $request->header('Last-Event-ID', $request->query('after', '0'));
        abort_unless(is_string($last) && ctype_digit($last) && strlen($last) <= 18, 422, 'Invalid event cursor.');
        $user = $request->user();

        return response()->stream(function () use ($conversation, $message, $last, $user) {
            $cursor = (int) $last;
            $deadline = microtime(true) + config('chat.stream_seconds');
            do {
                // Reauthorize every poll; revoked membership cannot keep receiving private output.
                $currentConversation = $conversation->fresh();
                if (! $currentConversation || ! Gate::forUser($user)->allows('view', $currentConversation)) {
                    break;
                }
                $events = MessageEvent::where('message_id', $message->id)->where('attempt', $message->attempt)->where('id', '>', $cursor)->orderBy('id')->limit(100)->get();
                foreach ($events as $event) {
                    echo "id: {$event->id}\nevent: {$event->type}\ndata: ".json_encode($event->payload, JSON_THROW_ON_ERROR)."\n\n";
                    $cursor = $event->id;
                }
                if (ob_get_level() > 0) {
                    ob_flush();
                } flush();
                $current = $message->fresh();
                if (! $current || (in_array($current->status, ['completed', 'failed'], true) && $events->count() < 100)) {
                    echo "event: end\ndata: {}\n\n";
                    break;
                }
                if (connection_aborted()) {
                    break;
                }
                echo ": heartbeat\n\n";
                usleep(250000);
            } while (microtime(true) < $deadline);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }

    public function models()
    {
        $model = config('chat.model');

        return response()->json(['data' => $model ? [['id' => $model, 'provider' => 'openai', 'capabilities' => ['text', 'streaming'], 'context_window' => config('chat.context_window'), 'max_output_tokens' => config('chat.max_output_tokens'), 'input_price_per_million' => config('chat.input_price_per_million'), 'output_price_per_million' => config('chat.output_price_per_million'), 'available' => (bool) config('chat.api_key')]] : []]);
    }
}

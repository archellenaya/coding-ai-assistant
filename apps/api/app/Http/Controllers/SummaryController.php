<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Services\Memory\SummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SummaryController extends Controller
{
    public function show(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        return response()->json(['data' => ConversationSummary::where('conversation_id', $conversation->id)->orderByDesc('through_sequence')->first()]);
    }

    public function store(Request $request, Conversation $conversation, SummaryService $service)
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate(['retry' => 'sometimes|boolean', 'summary_id' => 'nullable|uuid', 'attempt' => 'nullable|integer|min:1']);

        if ($data['retry'] ?? false) {
            abort_unless(isset($data['summary_id'], $data['attempt']), 422, 'Retry requires the failed summary ID and attempt.');
        }

        return response()->json(['data' => $service->request($conversation, $request->user(), $data['retry'] ?? false, $data['summary_id'] ?? null, $data['attempt'] ?? null)], 202);
    }
}

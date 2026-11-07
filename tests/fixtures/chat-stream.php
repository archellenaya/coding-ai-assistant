<?php

use App\Models\Conversation;
use App\Models\MessageEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Test-only fixture, supplied via PHP CLI by tests/smoke.py --chat-fixture.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('HTTP fixtures require the local development environment.');
}
$conversation = Conversation::whereKey($argv[1] ?? '')->where('title', 'HTTP smoke fixture')->firstOrFail();
if ($conversation->messages()->exists()) {
    throw new RuntimeException('Fixture requires a new empty smoke conversation.');
}
DB::transaction(function () use ($conversation) {
    $prompt = $conversation->messages()->create(['sequence' => 1, 'role' => 'user', 'status' => 'completed', 'content' => 'HTTP fixture prompt']);
    $reply = $conversation->messages()->create(['sequence' => 2, 'role' => 'assistant', 'status' => 'completed', 'content' => 'HTTP fixture — not an AI response', 'reply_to_id' => $prompt->id, 'provider' => 'fixture', 'model' => 'test-only']);
    $reset = MessageEvent::create(['message_id' => $reply->id, 'attempt' => 1, 'type' => 'reset', 'payload' => ['attempt' => 1]]);
    MessageEvent::create(['message_id' => $reply->id, 'attempt' => 1, 'type' => 'delta', 'payload' => ['text' => $reply->content]]);
    MessageEvent::create(['message_id' => $reply->id, 'attempt' => 1, 'type' => 'completed', 'payload' => ['usage' => []]]);
    echo json_encode(['message_id' => $reply->id, 'first_event_id' => $reset->id], JSON_THROW_ON_ERROR);
});

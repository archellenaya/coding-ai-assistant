<?php

namespace Tests\Feature;

use App\Contracts\LLMProvider;
use App\Jobs\GenerateChatResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\OpenAIProvider;
use App\Services\AI\ProviderEvent;
use App\Services\AI\ProviderException;
use App\Services\AI\SseDecoder;
use App\Services\Chat\ChatService;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['chat.api_key' => 'test-secret-do-not-expose', 'chat.model' => 'configured-model', 'chat.stream_seconds' => 0]);
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web')->withHeader('Origin', 'http://localhost');
        $org = Organization::create(['name' => 'Team']);
        DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->project = Project::create(['organization_id' => $org->id, 'name' => 'Private', 'cloud_processing_allowed' => true]);
        DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        $this->conversation = Conversation::create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'title' => 'Debugging']);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/conversations/'.$this->conversation->id.$suffix;
    }

    private function send(?string $key = null, string $text = 'Explain this code'): Message
    {
        $id = $this->postJson($this->url('/messages'), ['content' => $text, 'model' => 'configured-model', 'idempotency_key' => $key ?? (string) Str::uuid()])->assertAccepted()->json('data.id');

        return Message::findOrFail($id);
    }

    private function executeJob(Message $message): void
    {
        app()->call([new GenerateChatResponse($message->id, $message->attempt), 'handle']);
    }

    private function fakeProvider(array $events): void
    {
        $this->app->instance(LLMProvider::class, new class($events) implements LLMProvider
        {
            public function __construct(private array $events) {}

            public function stream(array $messages, string $model, int $maxOutputTokens): iterable
            {
                foreach ($this->events as $event) {
                    if ($event instanceof \Throwable) {
                        throw $event;
                    } yield $event;
                }
            }
        });
    }

    public function test_conversation_crud_search_pagination_and_cascades(): void
    {
        $base = '/api/v1/projects/'.$this->project->id.'/conversations';
        $id = $this->postJson($base, ['title' => 'Find bug'])->assertCreated()->json('data.id');
        $this->getJson($base.'?search=Find')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/conversations/'.$id, ['title' => 'Fixed bug'])->assertOk()->assertJsonPath('data.title', 'Fixed bug');
        $this->getJson('/api/v1/conversations/'.$id)->assertOk();
        $message = $this->send();
        $this->fakeProvider([new ProviderEvent('delta', 'Hello'), new ProviderEvent('completed')]);
        $this->executeJob($message);
        $this->getJson($this->url('/messages'))->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson($this->url())->assertNoContent();
        $this->assertDatabaseMissing('messages', ['conversation_id' => $this->conversation->id]);
        $this->assertDatabaseMissing('message_events', ['message_id' => $message->id]);
    }

    public function test_idempotent_submission_creates_one_job_and_one_prompt(): void
    {
        $key = (string) Str::uuid();
        $first = $this->send($key);
        $second = $this->send($key);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('jobs', 1);
        $this->postJson($this->url('/messages'), ['content' => 'Changed', 'model' => 'configured-model', 'idempotency_key' => $key])->assertConflict();
        $this->postJson($this->url('/messages'), ['content' => 'Next', 'model' => 'configured-model', 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->deleteJson($this->url())->assertConflict();
        Http::assertNothingSent();
    }

    public function test_cloud_permissions_models_archives_and_limits_are_enforced(): void
    {
        $payload = ['content' => 'Prompt', 'model' => 'configured-model', 'idempotency_key' => (string) Str::uuid()];
        $this->project->update(['cloud_processing_allowed' => false]);
        $this->postJson($this->url('/messages'), $payload)->assertForbidden();
        $this->project->update(['cloud_processing_allowed' => true, 'archived_at' => now()]);
        $this->postJson($this->url('/messages'), $payload)->assertConflict();
        $this->project->update(['archived_at' => null]);
        $this->postJson($this->url('/messages'), [...$payload, 'model' => 'unknown'])->assertUnprocessable();
        config(['chat.max_input_chars' => 2]);
        $this->postJson($this->url('/messages'), $payload)->assertUnprocessable();
        config(['chat.max_input_chars' => 24000, 'chat.api_key' => '']);
        $this->postJson($this->url('/messages'), $payload)->assertStatus(503);
        $this->getJson('/api/v1/models')->assertOk()->assertJsonPath('data.0.available', false)->assertDontSee('test-secret-do-not-expose');
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_other_users_cannot_access_chats_even_when_they_share_a_project(): void
    {
        $message = $this->send();
        $other = User::factory()->create();
        DB::table('organization_members')->insert(['organization_id' => $this->project->organization_id, 'user_id' => $other->id, 'role' => 'member']);
        DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $other->id]);
        $this->app['session']->flush();
        auth()->forgetGuards();
        $this->actingAs($other, 'web');
        $this->getJson('/api/v1/projects/'.$this->project->id.'/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('/messages'))->assertForbidden();
        $this->getJson($this->url('/messages/'.$message->id.'/stream'))->assertForbidden();
        $this->postJson($this->url('/messages/'.$message->id.'/retry'), ['attempt' => 1])->assertForbidden();
        $this->patchJson($this->url(), ['title' => 'Stolen'])->assertForbidden();
        $this->deleteJson($this->url())->assertForbidden();
        $unrelated = Project::create(['organization_id' => $this->project->organization_id, 'name' => 'Unassigned']);
        $this->getJson('/api/v1/projects/'.$unrelated->id.'/conversations')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_worker_persists_stream_usage_and_replays_only_events_after_cursor(): void
    {
        $message = $this->send();
        $this->fakeProvider([new ProviderEvent('delta', "Hello\n"), new ProviderEvent('delta', '世界'), new ProviderEvent('completed', usage: ['input_tokens' => 12, 'output_tokens' => 3], responseId: 'resp_test')]);
        $this->executeJob($message);
        $this->executeJob($message);
        $message->refresh();
        $this->assertSame('completed', $message->status);
        $this->assertSame("Hello\n世界", $message->content);
        $this->assertSame(12, $message->usage['input_tokens']);
        $this->assertDatabaseCount('message_events', 4);
        $first = MessageEvent::where('message_id', $message->id)->firstOrFail()->id;
        $response = $this->withHeader('Last-Event-ID', (string) $first)->get($this->url('/messages/'.$message->id.'/stream'))->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('event: completed', $content);
        $this->assertStringNotContainsString('event: reset', $content);
        $this->assertStringNotContainsString('test-secret-do-not-expose', $content);
    }

    public function test_failure_retains_partial_output_and_manual_retry_is_idempotent(): void
    {
        $message = $this->send();
        $this->fakeProvider([new ProviderEvent('delta', 'Partial'), new ProviderException('provider_stream_interrupted')]);
        $this->executeJob($message);
        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('Partial', $message->content);
        $url = $this->url('/messages/'.$message->id.'/retry');
        $this->postJson($url, ['attempt' => 1])->assertAccepted()->assertJsonPath('data.attempt', 2)->assertJsonPath('data.content', '');
        $this->postJson($url, ['attempt' => 1])->assertAccepted()->assertJsonPath('data.attempt', 2);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('messages', 2);
        // An old queued job must not claim a newer attempt.
        $this->executeJob($message);
        $this->assertSame('queued', $message->fresh()->status);
        $this->fakeProvider([new ProviderEvent('delta', 'Recovered'), new ProviderEvent('completed')]);
        $this->executeJob($message->fresh());
        $this->assertSame('Recovered', $message->fresh()->content);
    }

    public function test_worker_rechecks_privacy_and_membership_without_sending_any_code(): void
    {
        $message = $this->send();
        $this->project->update(['cloud_processing_allowed' => false]);
        $this->executeJob($message);
        $this->assertSame('failed', $message->fresh()->status);
        Http::assertNothingSent();
        $this->project->update(['cloud_processing_allowed' => true]);
        $this->postJson($this->url('/messages/'.$message->id.'/retry'), ['attempt' => 1])->assertAccepted();
        DB::table('project_members')->where('project_id', $this->project->id)->delete();
        $this->executeJob($message->fresh());
        $this->assertSame('failed', $message->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_recovery_marks_stale_work_failed_without_automatic_provider_calls(): void
    {
        $message = $this->send();
        Message::whereKey($message->id)->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('chat:recover')->expectsOutput('Recovered 1 stale responses.')->assertSuccessful();
        $this->assertSame('failed', $message->fresh()->status);
        $this->executeJob($message);
        Http::assertNothingSent();
    }

    public function test_context_contains_only_completed_turns_in_order_and_no_other_project(): void
    {
        $message = $this->send(text: 'First');
        $this->fakeProvider([new ProviderEvent('delta', 'Answer'), new ProviderEvent('completed')]);
        $this->executeJob($message);
        $next = $this->send(text: 'Second');
        $mock = $this->mock(LLMProvider::class);
        $mock->shouldReceive('stream')->once()->with([['role' => 'user', 'content' => 'First'], ['role' => 'assistant', 'content' => 'Answer'], ['role' => 'user', 'content' => 'Second']], 'configured-model', 1024)->andReturn([new ProviderEvent('delta', 'Next'), new ProviderEvent('completed')]);
        $this->executeJob($next);
        $this->assertSame('Next', $next->fresh()->content);
    }

    public function test_real_provider_adapter_parses_sse_and_sends_safe_responses_payload(): void
    {
        $frames = "event: response.output_text.delta\r\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\"}\r\n\r\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_a\",\"usage\":{\"input_tokens\":2,\"output_tokens\":1}}}\n\n";
        Http::fake(['api.openai.com/*' => Http::response($frames, 200, ['Content-Type' => 'text/event-stream'])]);
        $events = iterator_to_array(app(OpenAIProvider::class)->stream([['role' => 'user', 'content' => 'Hello']], 'configured-model', 1024));
        $this->assertSame('Hi', $events[0]->text);
        $this->assertSame(2, $events[1]->usage['input_tokens']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses' && $request['store'] === false && $request['stream'] === true && $request['max_output_tokens'] === 1024 && ! isset($request['tools']));
    }

    public function test_adapter_handles_errors_truncated_streams_and_malformed_frames_safely(): void
    {
        foreach ([Http::response('sensitive provider detail', 429), Http::response("data: invalid-json\n\n", 200), Http::response("data: {\"type\":\"response.output_text.delta\",\"delta\":\"Partial\"}\n\n", 200), Http::response("data: {\"type\":\"response.incomplete\"}\n\n", 200)] as $response) {
            Http::fake(['api.openai.com/*' => $response]);
            try {
                iterator_to_array(app(OpenAIProvider::class)->stream([], 'configured-model', 1024));
                $this->fail('Expected provider failure');
            } catch (ProviderException $error) {
                $this->assertSame('AI request failed.', $error->getMessage());
            }
        }
    }

    public function test_mismatched_message_stream_cursor_and_retry_limit_are_rejected(): void
    {
        $message = $this->send();
        $this->fakeProvider([new ProviderException]);
        $this->executeJob($message);
        $other = Conversation::create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'title' => 'Other']);
        $this->getJson('/api/v1/conversations/'.$other->id.'/messages/'.$message->id.'/stream')->assertNotFound();
        $this->withHeader('Last-Event-ID', 'invalid')->getJson($this->url('/messages/'.$message->id.'/stream'))->assertUnprocessable();
        $message->update(['attempt' => 3]);
        $this->postJson($this->url('/messages/'.$message->id.'/retry'), ['attempt' => 3])->assertUnprocessable();
    }

    public function test_real_queued_job_and_retry_stream_exclude_previous_attempt_events(): void
    {
        $message = $this->send();
        $this->fakeProvider([new ProviderEvent('delta', 'Old partial'), new ProviderException]);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'chat', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame('failed', $message->fresh()->status);
        $this->postJson($this->url('/messages/'.$message->id.'/retry'), ['attempt' => 1])->assertAccepted();
        $this->fakeProvider([new ProviderEvent('delta', 'New response'), new ProviderEvent('completed')]);
        $this->executeJob($message->fresh());
        $body = $this->get($this->url('/messages/'.$message->id.'/stream'))->assertOk()->streamedContent();
        $this->assertStringContainsString('New response', $body);
        $this->assertStringNotContainsString('Old partial', $body);
        $this->assertStringNotContainsString('event: failed', $body);
    }

    public function test_empty_search_and_code_whitespace_preservation(): void
    {
        $this->getJson('/api/v1/projects/'.$this->project->id.'/conversations?search=')->assertOk();
        $this->conversation->update(['title' => 'Literal %_ title']);
        $this->getJson('/api/v1/projects/'.$this->project->id.'/conversations?search=%25_')->assertOk()->assertJsonCount(1, 'data');
        $code = "    indented code\n\n";
        $message = $this->send(text: $code);
        $this->assertDatabaseHas('messages', ['id' => $message->reply_to_id, 'content' => $code]);
    }

    public function test_queue_failure_rolls_back_prompt_response_and_events(): void
    {
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        try {
            app(ChatService::class)->send($this->conversation, $this->owner, ['content' => 'Prompt', 'model' => 'configured-model', 'idempotency_key' => (string) Str::uuid()]);
            $this->fail('Expected queue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('message_events', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_sse_decoder_handles_fragmented_unicode_multiline_data_and_bounds(): void
    {
        $source = Utils::streamFor(": comment\r\ndata: {\"text\":\r\ndata: \"世界\"}\r\n\r\n");
        $stream = FnStream::decorate($source, ['read' => fn ($length) => $source->read(min(3, $length))]);
        $events = iterator_to_array(app(SseDecoder::class)->decode($stream));
        $this->assertSame('世界', json_decode($events[0], true, flags: JSON_THROW_ON_ERROR)['text']);
        $this->expectException(ProviderException::class);
        iterator_to_array(app(SseDecoder::class)->decode(Utils::streamFor('data: '.str_repeat('x', 1048577)."\n\n")));
    }
}

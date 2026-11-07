<?php

namespace Tests\Feature;

use App\Contracts\LLMProvider;
use App\Jobs\GenerateChatResponse;
use App\Jobs\SummarizeConversation;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Solution;
use App\Models\User;
use App\Services\AI\ContextBuilder;
use App\Services\AI\ModelCatalog;
use App\Services\AI\OllamaProvider;
use App\Services\AI\ProviderAvailability;
use App\Services\AI\ProviderEvent;
use App\Services\AI\ProviderException;
use App\Services\AI\SolutionCache;
use App\Services\AI\TokenCounter;
use App\Services\AI\UsageLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptimizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['chat.api_key' => 'private-test-key', 'chat.model' => 'cloud-model', 'chat.context_window' => 8192, 'chat.tokenizer' => 'utf8-bytes', 'chat.max_output_tokens' => 128, 'chat.input_price_per_million' => 1, 'chat.output_price_per_million' => 2, 'ai.ollama_model' => 'local-model', 'ai.models' => [], 'memory.token' => 'internal-token']);
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web')->withHeader('Origin', 'http://localhost');
        $org = Organization::create(['name' => 'Team']);
        DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->project = Project::create(['organization_id' => $org->id, 'name' => 'Project', 'cloud_processing_allowed' => true]);
        DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        $this->conversation = Conversation::create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'title' => 'Preferences', 'provider_mode' => 'openai'])->fresh();
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/conversations/'.$this->conversation->id.$suffix;
    }

    private function prefs(string $mode, ?string $model = null, bool $fallback = false): void
    {
        $this->patchJson($this->url('/preferences'), ['provider_mode' => $mode, 'preferred_model' => $model, 'allow_fallback' => $fallback])->assertOk();
        $this->conversation->refresh();
    }

    private function send(array $extra = []): Message
    {
        $id = $this->postJson($this->url('/messages'), $extra + ['content' => 'Explain authentication code', 'idempotency_key' => (string) Str::uuid()])->assertAccepted()->json('data.id');

        return Message::findOrFail($id);
    }

    private function execute(Message $message): void
    {
        app()->call([new GenerateChatResponse($message->id, $message->attempt), 'handle']);
    }

    private function fakeGeneration(string $provider = 'openai', bool $fail = false): void
    {
        $mock = $this->mock($provider === 'ollama' ? OllamaProvider::class : LLMProvider::class);
        $mock->shouldReceive('stream')->once()->andReturnUsing(function () use ($fail) {
            yield new ProviderEvent('delta', 'Synthetic tested transport answer.');
            if ($fail) {
                throw new ProviderException('provider_stream_interrupted');
            }yield new ProviderEvent('completed', usage: ['input_tokens' => 10, 'output_tokens' => 5, 'cached_tokens' => 2]);
        });
    }

    private function compatibility(): array
    {
        return ['framework' => 'Laravel', 'framework_version' => '12', 'dependencies' => ['php' => '8.3'], 'environment' => 'Linux test fixture', 'git_commit' => null];
    }

    private function solution(): Solution
    {
        $id = $this->postJson('/api/v1/projects/'.$this->project->id.'/solutions', ['error_signature' => 'fixture-error', 'content' => 'Manually tested fix', 'compatibility' => $this->compatibility(), 'evidence' => ['command' => 'php artisan test', 'exit_code' => 0, 'result' => 'Synthetic passed test evidence', 'attestation' => true]])->assertCreated()->assertJsonPath('data.evidence.attested_by', $this->owner->id)->json('data.id');

        return Solution::findOrFail($id);
    }

    public function test_new_defaults_and_preferences_persist_without_losing_history_or_memory(): void
    {
        $id = $this->postJson('/api/v1/projects/'.$this->project->id.'/conversations', ['title' => 'Auto default'])->assertCreated()->assertJsonPath('data.provider_mode', 'auto')->json('data.id');
        $this->prefs('ollama', 'local-model');
        $this->getJson($this->url('/preferences'))->assertOk()->assertJsonPath('data.preferred_model', 'local-model');
        $message = $this->send();
        $this->fakeGeneration('ollama');
        $this->execute($message);
        $this->prefs('openai', 'cloud-model');
        $this->getJson($this->url('/messages'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.provider', 'ollama');
        $this->assertDatabaseCount('memories', 1);
        $this->assertSame('completed', $message->fresh()->status);
        $this->assertSame('auto', Conversation::findOrFail($id)->provider_mode);
    }

    public function test_explicit_local_mode_works_without_cloud_permission_and_has_zero_api_cost(): void
    {
        $this->project->update(['cloud_processing_allowed' => false]);
        $this->prefs('ollama', 'local-model');
        $message = $this->send();
        $this->fakeGeneration('ollama');
        $this->execute($message);
        $call = ModelCall::firstOrFail();
        $this->assertSame('completed', $call->status);
        $this->assertEquals(0, $call->estimated_micro_usd);
        $this->assertSame('ollama', $message->fresh()->provider);
        Http::assertNothingSent();
    }

    public function test_forced_provider_validation_never_silently_switches(): void
    {
        $this->patchJson($this->url('/preferences'), ['provider_mode' => 'openai', 'preferred_model' => 'local-model', 'allow_fallback' => false])->assertUnprocessable();
        $this->patchJson($this->url('/preferences'), ['provider_mode' => 'ollama', 'preferred_model' => 'local-model', 'allow_fallback' => true])->assertUnprocessable();
        $this->prefs('openai', 'cloud-model');
        $message = $this->send();
        $this->fakeGeneration('openai', true);
        $this->mock(OllamaProvider::class)->shouldNotReceive('stream');
        $this->execute($message);
        $this->assertSame('failed', $message->fresh()->status);
        $call = ModelCall::firstOrFail();
        $this->assertSame('uncertain', $call->status);
        $this->assertGreaterThan(0, $call->reserved_micro_usd);
        $this->assertSame('openai', $call->provider);
    }

    public function test_auto_fallback_requires_consent_and_records_actual_provider(): void
    {
        $this->conversation->update(['provider_mode' => 'auto', 'preferred_model' => 'local-model', 'allow_fallback' => false]);
        $this->app->instance(ProviderAvailability::class, new class extends ProviderAvailability
        {
            public function check(array $m): array
            {
                return ['available' => $m['provider'] === 'openai', 'error' => 'offline', 'latency_ms' => 2];
            }
        });
        $this->postJson($this->url('/messages'), ['content' => 'Explain', 'idempotency_key' => (string) Str::uuid()])->assertStatus(503)->assertJsonPath('code', 'provider_unavailable');
        $this->conversation->update(['allow_fallback' => true]);
        $message = $this->send();
        $this->fakeGeneration();
        $this->execute($message);
        $this->assertSame('openai', $message->fresh()->provider);
        $this->assertSame('authorized_availability_fallback', $message->fresh()->routing['decision']);
    }

    public function test_local_only_overrides_auto_and_never_probes_cloud(): void
    {
        $this->conversation->update(['provider_mode' => 'auto', 'preferred_model' => 'cloud-model', 'allow_fallback' => true]);
        $this->project->update(['cloud_processing_allowed' => false]);
        $this->app->instance(ProviderAvailability::class, new class extends ProviderAvailability
        {
            public function check(array $m): array
            {
                if ($m['provider'] === 'openai') {
                    throw new \RuntimeException('Cloud probe forbidden');
                }

                return ['available' => true, 'error' => null, 'latency_ms' => 1];
            }
        });
        $message = $this->send();
        $this->fakeGeneration('ollama');
        $this->execute($message);
        $this->assertSame('ollama', $message->fresh()->provider);
        Http::assertNothingSent();
    }

    public function test_queued_request_snapshots_preferences_and_idempotency_replays_original_selection(): void
    {
        $this->prefs('ollama', 'local-model');
        $key = (string) Str::uuid();
        $message = $this->send(['idempotency_key' => $key]);
        $this->prefs('openai', 'cloud-model');
        $this->assertSame($message->id, $this->send(['idempotency_key' => $key])->id);
        $this->fakeGeneration('ollama');
        $this->execute($message);
        $this->assertSame('ollama', $message->fresh()->provider);
        Queue::assertPushed(GenerateChatResponse::class, 1);
    }

    public function test_revoked_cloud_policy_prevents_queued_inference(): void
    {
        $message = $this->send();
        $this->project->update(['cloud_processing_allowed' => false]);
        $this->mock(LLMProvider::class)->shouldNotReceive('stream');
        $this->execute($message);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertDatabaseCount('model_calls', 0);
        $this->postJson($this->url('/summary'))->assertForbidden();
    }

    public function test_context_budget_selects_whole_recent_pairs_and_measures_reduction(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $p = $this->conversation->messages()->create(['sequence' => $i * 2 + 1, 'role' => 'user', 'status' => 'completed', 'content' => str_repeat('history source ', 80)]);
            $this->conversation->messages()->create(['sequence' => $i * 2 + 2, 'role' => 'assistant', 'status' => 'completed', 'content' => str_repeat('whole code block ', 80), 'reply_to_id' => $p->id]);
        }
        $model = app(ModelCatalog::class)->find('cloud-model');
        $model['context_window'] = 4096;
        $result = app(ContextBuilder::class)->build($this->conversation, $this->owner, 'Current prompt must stay intact', $model);
        $this->assertGreaterThan(0, $result['metrics']['reduction_fraction']);
        $this->assertLessThanOrEqual(4096, $result['metrics']['input_tokens'] + $result['metrics']['output_reserved'] + $result['metrics']['safety_reserved']);
        $this->assertSame('Current prompt must stay intact', end($result['messages'])['content']);
        foreach ($result['messages'] as $m) {
            if ($m['role'] === 'assistant') {
                $this->assertSame(str_repeat('whole code block ', 80), $m['content']);
            }
        }
        $this->expectException(ProviderException::class);
        app(ContextBuilder::class)->build($this->conversation, $this->owner, str_repeat('x', 5000), $model);
    }

    public function test_context_reuses_ready_summary_without_buying_hidden_summary(): void
    {
        ConversationSummary::create(['conversation_id' => $this->conversation->id, 'through_sequence' => 2, 'status' => 'ready', 'content' => 'Unverified previous decisions', 'model' => 'cloud-model']);
        $result = app(ContextBuilder::class)->build($this->conversation, $this->owner, 'Explain code', app(ModelCatalog::class)->find('cloud-model'));
        $this->assertNotNull($result['metrics']['summary_id']);
        $this->assertStringContainsString('UNVERIFIED', $result['messages'][0]['content']);
        Queue::assertNotPushed(SummarizeConversation::class);
    }

    public function test_budget_reservations_settlement_and_duplicate_inference_guard(): void
    {
        config(['ai.daily_user_budget' => 2000]);
        $ledger = app(UsageLedger::class);
        $model = app(ModelCatalog::class)->find('cloud-model');
        $metrics = ['input_tokens' => 1000, 'output_reserved' => 100, 'safety_reserved' => 0];
        $first = $ledger->reserve($this->conversation, $model, 'message', (string) Str::uuid(), 1, $metrics);
        try {
            $ledger->reserve($this->conversation, $model, 'message', (string) Str::uuid(), 1, $metrics);
            $this->fail('Budget allowed excess');
        } catch (ProviderException $e) {
            $this->assertSame('budget_exceeded', $e->errorCode);
        }
        $ledger->complete($first, ['input_tokens' => 10, 'output_tokens' => 5, 'cached_tokens' => 2], 123);
        $this->assertEquals(20, $first->fresh()->estimated_micro_usd);
        $second = $ledger->reserve($this->conversation, $model, 'message', (string) Str::uuid(), 1, $metrics);
        $this->assertSame('reserved', $second->status);
        $this->expectException(ProviderException::class);
        $ledger->reserve($this->conversation, $model, 'message', $second->source_id, 1, $metrics);
    }

    public function test_project_and_organization_budget_caps_and_deletion_do_not_erase_spend(): void
    {
        $ledger = app(UsageLedger::class);
        $model = app(ModelCatalog::class)->find('cloud-model');
        $metrics = ['input_tokens' => 100, 'output_reserved' => 10, 'safety_reserved' => 0];
        $first = $ledger->reserve($this->conversation, $model, 'message', (string) Str::uuid(), 1, $metrics);
        $ledger->complete($first, ['input_tokens' => 100, 'output_tokens' => 10], 1);
        $this->project->delete();
        $this->assertDatabaseHas('model_calls', ['id' => $first->id, 'project_id' => null, 'user_id' => $this->owner->id]);
        $this->getJson('/api/v1/usage')->assertOk()->assertJsonPath('totals.0.calls', 1);
    }

    public function test_cache_requires_test_evidence_and_reuses_only_compatible_private_record(): void
    {
        $solution = $this->solution();
        $this->getJson('/api/v1/projects/'.$this->project->id.'/solutions?search=fixture')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/projects/'.$this->project->id.'/solutions?search=other')->assertOk()->assertJsonCount(0, 'data');
        $this->mock(LLMProvider::class)->shouldNotReceive('stream');
        $this->mock(OllamaProvider::class)->shouldNotReceive('stream');
        $message = $this->send(['solution_context' => ['error_signature' => 'fixture-error', 'compatibility' => $this->compatibility()]]);
        $this->execute($message);
        $this->assertSame('cache', $message->fresh()->provider);
        $this->assertSame('Manually tested fix', $message->fresh()->content);
        $this->assertTrue(ModelCall::firstOrFail()->cache_hit);
        $this->postJson('/api/v1/solutions/'.$solution->id.'/invalidate')->assertOk()->assertJsonPath('data.state', 'invalidated');
        $this->assertNull(app(SolutionCache::class)->lookup($this->project, $this->owner, ['error_signature' => 'fixture-error', 'compatibility' => $this->compatibility()]));
    }

    public function test_cache_invalidates_for_dependency_environment_or_repository_changes(): void
    {
        $this->solution();
        $cache = app(SolutionCache::class);
        $context = ['error_signature' => 'fixture-error', 'compatibility' => $this->compatibility()];
        $this->assertNotNull($cache->lookup($this->project, $this->owner, $context));
        $changed = $context;
        $changed['compatibility']['dependencies']['php'] = '8.4';
        $this->assertNull($cache->lookup($this->project, $this->owner, $changed));
        Repository::create(['project_id' => $this->project->id, 'name' => 'Changed snapshot', 'path' => 'repo', 'revision' => str_repeat('a', 40)]);
        $this->assertNull($cache->lookup($this->project, $this->owner, $context));
    }

    public function test_solution_evidence_cannot_be_forged_or_automatically_verified(): void
    {
        $data = ['error_signature' => 'error', 'content' => 'Fix', 'compatibility' => $this->compatibility(), 'evidence' => ['command' => 'test', 'exit_code' => 1, 'result' => 'failed', 'attestation' => true]];
        $this->postJson('/api/v1/projects/'.$this->project->id.'/solutions', $data)->assertUnprocessable();
        $data['evidence']['exit_code'] = 0;
        $data['evidence']['attested_by'] = 'forged';
        $this->postJson('/api/v1/projects/'.$this->project->id.'/solutions', $data)->assertUnprocessable();
        unset($data['evidence']['attested_by']);
        $data['compatibility']['dependencies'] = 'invalid map';
        $this->postJson('/api/v1/projects/'.$this->project->id.'/solutions', $data)->assertUnprocessable();
        $this->assertDatabaseCount('solutions', 0);
    }

    public function test_cross_user_preferences_cache_and_usage_are_private(): void
    {
        $solution = $this->solution();
        $other = User::factory()->create();
        DB::table('organization_members')->insert(['organization_id' => $this->project->organization_id, 'user_id' => $other->id, 'role' => 'member']);
        DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $other->id]);
        auth()->forgetGuards();
        $this->actingAs($other, 'web');
        $this->getJson($this->url('/preferences'))->assertForbidden();
        $this->getJson('/api/v1/projects/'.$this->project->id.'/solutions')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/solutions/'.$solution->id.'/invalidate')->assertForbidden();
        $this->getJson('/api/v1/organizations/'.$this->project->organization_id.'/usage')->assertForbidden();
        $this->getJson('/api/v1/projects/'.$this->project->id.'/usage')->assertOk()->assertJsonPath('scope', 'current_user')->assertJsonCount(0, 'data.data');
    }

    public function test_local_summary_uses_snapshotted_provider_and_accounts_for_usage(): void
    {
        $this->prefs('ollama', 'local-model');
        $message = $this->send();
        $this->fakeGeneration('ollama');
        $this->execute($message);
        $this->project->update(['cloud_processing_allowed' => false]);
        $id = $this->postJson($this->url('/summary'))->assertAccepted()->assertJsonPath('data.provider', 'ollama')->json('data.id');
        $this->fakeGeneration('ollama');
        app()->call([new SummarizeConversation($id, 1), 'handle']);
        $this->assertSame('ready', ConversationSummary::findOrFail($id)->status);
        $this->assertDatabaseCount('model_calls', 2);
    }

    public function test_live_metadata_probes_are_bounded_and_do_not_send_private_text(): void
    {
        $live = new ProviderAvailability;
        Http::fake(['https://api.openai.com/v1/models/cloud-model' => Http::response(['id' => 'cloud-model']), 'http://ollama:11434/api/show' => Http::response(['capabilities' => ['completion'], 'model_info' => ['fixture.context_length' => 8192]])]);
        $this->assertTrue($live->check(app(ModelCatalog::class)->find('cloud-model'))['available']);
        $this->assertTrue($live->check(app(ModelCatalog::class)->find('local-model'))['available']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/models/cloud-model' && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer private-test-key'));
        Http::assertSent(fn ($r) => $r->url() === 'http://ollama:11434/api/show' && $r->data() === ['model' => 'local-model']);
        $this->app->instance(ProviderAvailability::class, $live);
        $this->getJson('/api/v1/providers')->assertOk()->assertDontSee('private-test-key');
    }

    public function test_remote_ollama_and_cloud_aliases_fail_closed(): void
    {
        $live = new ProviderAvailability;
        config(['ai.ollama_url' => 'https://public.example.com']);
        $this->assertFalse($live->check(app(ModelCatalog::class)->find('local-model'))['available']);
        Http::assertNothingSent();
        config(['ai.ollama_url' => 'http://ollama:11434']);
        Http::fake(['*' => Http::response(['capabilities' => ['completion'], 'remote_host' => 'https://ollama.com'])]);
        $this->assertFalse($live->check(app(ModelCatalog::class)->find('local-model'))['available']);
    }

    public function test_ollama_ndjson_streams_text_and_normalized_usage(): void
    {
        $fixture = "{\"model\":\"local-model\",\"message\":{\"content\":\"Hello\"},\"done\":false}\n{\"model\":\"local-model\",\"done\":true,\"done_reason\":\"stop\",\"prompt_eval_count\":8,\"eval_count\":2}\n";
        Http::fake(['http://ollama:11434/api/chat' => Http::response($fixture, 200, ['Content-Type' => 'application/x-ndjson'])]);
        $events = iterator_to_array((new OllamaProvider(new ProviderAvailability, new ModelCatalog))->stream([['role' => 'user', 'content' => 'Fixture']], 'local-model', 64));
        $this->assertSame('Hello', $events[0]->text);
        $this->assertSame(8, $events[1]->usage['input_tokens']);
        Http::assertSent(fn ($r) => $r['options']['num_predict'] === 64 && $r['options']['num_ctx'] === 8192 && ! isset($r['tools']));
    }

    public function test_ollama_incomplete_response_never_reports_success(): void
    {
        Http::fake(['*' => Http::response("{\"message\":{\"content\":\"Partial\"},\"done\":false}\n")]);
        $this->expectException(ProviderException::class);
        iterator_to_array((new OllamaProvider(new ProviderAvailability, new ModelCatalog))->stream([['role' => 'user', 'content' => 'Fixture']], 'local-model', 64));
    }

    public function test_token_counter_authenticates_and_fails_without_valid_counts(): void
    {
        Http::fake(['*' => Http::response(['counts' => [3]])]);
        $this->assertSame([3], (new TokenCounter)->countTexts(['Synthetic'], 'o200k_base'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.config('memory.token')) && $r['encoding'] === 'o200k_base');
        $this->expectException(ProviderException::class);
        (new TokenCounter)->countTexts(['a', 'b'], 'o200k_base');
    }

    public function test_each_scope_budget_blocks_inference_and_rolls_back_reservation(): void
    {
        $ledger = app(UsageLedger::class);
        $model = app(ModelCatalog::class)->find('cloud-model');
        foreach (['project', 'organization'] as $scope) {
            config(['ai.daily_project_budget' => 50000000, 'ai.daily_organization_budget' => 100000000, 'ai.daily_'.$scope.'_budget' => 0]);
            try {
                $ledger->reserve($this->conversation, $model, 'message', (string) Str::uuid(), 1, ['input_tokens' => 10, 'output_reserved' => 10]);
                $this->fail('Budget was bypassed');
            } catch (ProviderException $error) {
                $this->assertSame('budget_exceeded', $error->errorCode);
            }
            $this->assertDatabaseCount('model_calls', 0);
            $this->assertDatabaseCount('usage_ledgers', 0);
        }
    }

    public function test_registry_is_durable_and_retired_models_cannot_be_selected(): void
    {
        config(['ai.models' => [['id' => 'extra-model', 'provider' => 'ollama', 'private_key' => 'must-not-leak']]]);
        $this->getJson('/api/v1/providers')->assertOk()->assertDontSee('must-not-leak');
        $this->assertDatabaseHas('ai_models', ['identifier' => 'extra-model', 'active' => true]);
        config(['ai.models' => []]);
        app(ModelCatalog::class)->models();
        $this->assertDatabaseHas('ai_models', ['identifier' => 'extra-model', 'active' => false]);
        $this->patchJson($this->url('/preferences'), ['provider_mode' => 'ollama', 'preferred_model' => 'extra-model', 'allow_fallback' => false])->assertUnprocessable();
    }

    public function test_stale_recovery_retains_uncertain_spend_before_explicit_retry(): void
    {
        $message = $this->send();
        $call = app(UsageLedger::class)->reserve($this->conversation, app(ModelCatalog::class)->find('cloud-model'), 'message', $message->id, 1, ['input_tokens' => 100, 'output_reserved' => 100]);
        $message->update(['status' => 'streaming', 'started_at' => now()->subMinutes(10)]);
        $message->forceFill(['updated_at' => now()->subMinutes(10)])->save();
        $this->artisan('chat:recover')->assertSuccessful();
        $this->assertSame('uncertain', $call->fresh()->status);
        $this->assertGreaterThan(0, $call->fresh()->reserved_micro_usd);
    }

    public function test_cache_never_reuses_an_ai_claim_or_unattested_record(): void
    {
        $solution = $this->solution();
        $solution->update(['evidence' => ['kind' => 'ai_generated', 'exit_code' => 0, 'attestation' => true, 'attested_by' => $this->owner->id]]);
        $this->assertNull(app(SolutionCache::class)->lookup($this->project, $this->owner, ['error_signature' => 'fixture-error', 'compatibility' => $this->compatibility()]));
    }

    public function test_blank_legacy_prices_use_unknown_estimates_without_breaking_catalog(): void
    {
        config(['chat.input_price_per_million' => '', 'chat.output_price_per_million' => '']);
        $model = app(ModelCatalog::class)->find('cloud-model');
        $this->assertNull($model['input_price_per_million']);
    }

    public function test_local_zero_fee_requests_still_work_when_cloud_reservations_exceed_a_lowered_cap(): void
    {
        $ledger = app(UsageLedger::class);
        $ledger->reserve($this->conversation, app(ModelCatalog::class)->find('cloud-model'), 'message', (string) Str::uuid(), 1, ['input_tokens' => 100, 'output_reserved' => 100]);
        config(['ai.daily_user_budget' => 0, 'ai.daily_project_budget' => 0, 'ai.daily_organization_budget' => 0]);
        $call = $ledger->reserve($this->conversation, app(ModelCatalog::class)->find('local-model'), 'message', (string) Str::uuid(), 1, ['input_tokens' => 100, 'output_reserved' => 100]);
        $this->assertEquals(0, $call->reserved_micro_usd);
    }

    public function test_existing_model_identifier_cannot_change_provider_and_reroute_history(): void
    {
        app(ModelCatalog::class)->models();
        config(['chat.model' => null, 'ai.models' => [['id' => 'cloud-model', 'provider' => 'ollama']]]);
        $this->expectException(ProviderException::class);
        app(ModelCatalog::class)->models();
    }
}

<?php

namespace Tests\Feature;

use App\Contracts\EmbeddingProvider;
use App\Contracts\LLMProvider;
use App\Jobs\GenerateChatResponse;
use App\Jobs\IndexRepository;
use App\Models\CodeChunk;
use App\Models\Conversation;
use App\Models\IndexingJob;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Models\User;
use App\Services\AI\ProviderEvent;
use App\Services\Memory\EmbeddingResult;
use App\Services\Repository\CodeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepositoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['memory.dimensions' => 3, 'memory.model' => 'test', 'memory.version' => 'test-v1', 'chat.model' => 'test-chat', 'chat.api_key' => 'fake', 'memory.intelligence_url' => 'http://intelligence:8001', 'memory.token' => 'internal-test']);
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web')->withHeader('Origin', 'http://localhost');
        $org = Organization::create(['name' => 'Team']);
        DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->project = Project::create(['organization_id' => $org->id, 'name' => 'Project', 'cloud_processing_allowed' => false]);
        DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        $this->app->instance(EmbeddingProvider::class, new class($this) implements EmbeddingProvider
        {
            public function __construct(private RepositoryTest $test) {}

            public function embed(string $text): EmbeddingResult
            {
                $this->test->countEmbedding();

                return new EmbeddingResult([1., 0., 0.], 'test', 'test-v1', str_repeat('a', 64), 'utf8-384-mean-v1');
            }
        });
    }

    public function countEmbedding(): void
    {
        $this->calls++;
    }

    private function url(string $tail = 'repositories'): string
    {
        return '/api/v1/projects/'.$this->project->id.'/'.$tail;
    }

    private function repository(): Repository
    {
        return Repository::findOrFail($this->postJson($this->url(), ['name' => 'Source', 'path' => 'repo'])->assertCreated()->json('data.id'));
    }

    private function fixture(string $content = 'function renew_session() { return refresh(); }', string $path = 'auth.ts'): array
    {
        return ['revision' => str_repeat('b', 40), 'parser_version' => 'symbols-v1', 'languages' => ['typescript'], 'frameworks' => ['React'], 'files' => [['path' => $path, 'hash' => hash('sha256', $content), 'language' => 'typescript', 'parser' => 'tree-sitter', 'chunks' => [['symbol' => 'renew_session', 'start_line' => 1, 'end_line' => 1, 'content' => $content]]]]];
    }

    private function runIndex(Repository $repo, ?array $snapshot = null): IndexingJob
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['http://intelligence:8001/v1/repositories/snapshot' => Http::response($snapshot ?? $this->fixture())]);
        $id = $this->postJson('/api/v1/repositories/'.$repo->id.'/index')->assertAccepted()->json('data.id');
        app()->call([new IndexRepository($id), 'handle']);

        return IndexingJob::findOrFail($id);
    }

    public function test_registration_validation_and_duplicate_limits(): void
    {
        foreach (['../repo', '/repo', 'repo//x', 'repo/../x', 'repo/.git', 'https://host/repo', 'repo\\x'] as $path) {
            $this->postJson($this->url(), ['name' => 'bad', 'path' => $path])->assertUnprocessable();
        }
        $repo = $this->repository();
        $this->postJson($this->url(), ['name' => 'Again', 'path' => 'repo'])->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/repositories/'.$repo->id)->assertOk()->assertJsonPath('job', null);
    }

    public function test_incremental_hash_reuse_changes_deletions_and_provenance(): void
    {
        $repo = $this->repository();
        $this->assertSame('ready', $this->runIndex($repo)->status);
        $chunk = CodeChunk::firstOrFail();
        $calls = $this->calls;
        $second = $this->runIndex($repo);
        $this->assertSame(1, $second->stats['unchanged']);
        $this->assertSame($calls + 1, $this->calls); // Only profile probe, no source re-embedding.
        $this->assertSame($chunk->id, CodeChunk::firstOrFail()->id);
        $third = $this->runIndex($repo, $this->fixture('function changed() {}', 'new.ts'));
        $this->assertSame(1, $third->stats['deleted']);
        $this->assertDatabaseMissing('code_chunks', ['id' => $chunk->id]);
        $this->getJson('/api/v1/repositories/'.$repo->id.'/files')->assertOk()->assertJsonPath('data.0.path', 'new.ts');
        $this->getJson('/api/v1/code-chunks/'.CodeChunk::firstOrFail()->id)->assertOk()->assertJsonMissingPath('data.values')->assertJsonPath('repository.revision', str_repeat('b', 40));
        $this->runIndex($repo, $this->fixture() + []);
    }

    public function test_failed_snapshot_keeps_previous_index_and_is_retryable(): void
    {
        $repo = $this->repository();
        $this->runIndex($repo);
        $id = CodeChunk::firstOrFail()->id;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $work = IndexingJob::create(['repository_id' => $repo->id, 'user_id' => $this->owner->id, 'status' => 'queued']);
        app()->call([new IndexRepository($work->id), 'handle']);
        $this->assertSame('failed', $work->fresh()->status);
        $this->assertDatabaseHas('code_chunks', ['id' => $id]);
        $this->assertSame('ready', $this->runIndex($repo)->status);
    }

    public function test_enqueue_is_idempotent_and_deletion_cancels_work(): void
    {
        $repo = $this->repository();
        $route = '/api/v1/repositories/'.$repo->id.'/index';
        $id = $this->postJson($route)->assertAccepted()->json('data.id');
        $this->postJson($route)->assertAccepted()->assertJsonPath('data.id', $id);
        Queue::assertPushed(IndexRepository::class, 1);
        $this->deleteJson('/api/v1/repositories/'.$repo->id)->assertNoContent();
        app()->call([new IndexRepository($id), 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('indexing_jobs', 0);
    }

    public function test_cross_project_and_member_permissions(): void
    {
        $repo = $this->repository();
        $this->runIndex($repo);
        $chunk = CodeChunk::firstOrFail();
        $other = User::factory()->create();
        auth()->forgetGuards();
        $this->actingAs($other, 'web');
        foreach ([$this->url(), '/api/v1/repositories/'.$repo->id, '/api/v1/repositories/'.$repo->id.'/files', '/api/v1/code-chunks/'.$chunk->id] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson($this->url('code/search'), ['query' => 'refresh'])->assertForbidden();
        DB::table('organization_members')->insert(['organization_id' => $this->project->organization_id, 'user_id' => $other->id, 'role' => 'member']);
        DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $other->id]);
        $this->getJson('/api/v1/code-chunks/'.$chunk->id)->assertOk();
        $this->postJson('/api/v1/repositories/'.$repo->id.'/index')->assertForbidden();
        $this->postJson($this->url(), ['name' => 'Bad', 'path' => 'new'])->assertForbidden();
        $this->deleteJson('/api/v1/repositories/'.$repo->id)->assertForbidden();
        DB::table('organization_members')->where('user_id', $other->id)->delete();
        $this->getJson('/api/v1/code-chunks/'.$chunk->id)->assertForbidden();
    }

    public function test_revoked_access_before_job_and_during_embedding_prevents_publication(): void
    {
        $repo = $this->repository();
        $work = IndexingJob::create(['repository_id' => $repo->id, 'user_id' => $this->owner->id, 'status' => 'queued']);
        DB::table('project_members')->where('user_id', $this->owner->id)->delete();
        app()->call([new IndexRepository($work->id), 'handle']);
        Http::assertNothingSent();
        $this->assertSame('failed', $work->fresh()->status);
        DB::table('project_members')->insert(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embed(string $text): EmbeddingResult
            {
                DB::table('project_members')->delete();

                return new EmbeddingResult([1., 0., 0.], 'test', 'test-v1', str_repeat('a', 64), 'utf8-384-mean-v1');
            }
        });
        $this->assertSame('failed', $this->runIndex($repo)->status);
        $this->assertDatabaseCount('code_chunks', 0);
    }

    public function test_hybrid_symbol_retrieval_is_scoped_and_degrades_safely(): void
    {
        $repo = $this->repository();
        $this->runIndex($repo);
        $other = Project::create(['organization_id' => $this->project->organization_id, 'name' => 'Other']);
        $foreign = Repository::create(['project_id' => $other->id, 'name' => 'Other', 'path' => 'repo']);
        // Same content/symbol in another project must never be a candidate.
        $f = RepositoryFile::firstOrFail()->replicate();
        $f->repository_id = $foreign->id;
        $f->save();
        $c = CodeChunk::firstOrFail()->replicate(['search_vector']);
        $c->repository_file_id = $f->id;
        $c->save();
        $response = $this->postJson($this->url('code/search'), ['query' => 'renew_session'])->assertOk()->assertJsonPath('retrieval.mode', 'hybrid')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.chunk.values');
        $this->assertSame($repo->id, $response->json('data.0.chunk.repository_id'));
        $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embed(string $text): EmbeddingResult
            {
                throw new \RuntimeException('offline');
            }
        });
        $this->postJson($this->url('code/search'), ['query' => 'renew_session'])->assertOk()->assertJsonPath('retrieval.degraded', true)->assertJsonPath('retrieval.mode', 'lexical')->assertJsonCount(1, 'data');
    }

    public function test_embedding_profile_change_rebuilds_unchanged_files_and_excludes_old_vectors(): void
    {
        $repo = $this->repository();
        $this->runIndex($repo);
        $old = CodeChunk::firstOrFail()->id;
        config(['memory.dimensions' => 2]);
        $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embed(string $text): EmbeddingResult
            {
                return new EmbeddingResult([1., 0.], 'test', 'test-v1', str_repeat('c', 64), 'utf8-384-mean-v1');
            }
        });
        $this->postJson($this->url('code/search'), ['query' => 'unrelated paraphrase'])->assertOk()->assertJsonCount(0, 'data');
        $job = $this->runIndex($repo);
        $this->assertSame('ready', $job->status);
        $this->assertSame(1, $job->stats['changed']);
        $this->assertDatabaseMissing('code_chunks', ['id' => $old]);
        $this->postJson($this->url('code/search'), ['query' => 'unrelated paraphrase'])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_empty_snapshot_cleans_deleted_files_and_recovery_blocks_late_job(): void
    {
        $repo = $this->repository();
        $this->runIndex($repo);
        $data = $this->fixture();
        $data['files'] = [];
        $this->runIndex($repo, $data);
        $this->assertDatabaseCount('code_chunks', 0);
        $work = IndexingJob::create(['repository_id' => $repo->id, 'user_id' => $this->owner->id, 'status' => 'indexing']);
        $work->forceFill(['updated_at' => now()->subMinutes(6)])->save();
        $this->artisan('repositories:recover')->assertSuccessful();
        $this->assertSame('failed', $work->fresh()->status);
        app()->call([new IndexRepository($work->id), 'handle']);
        $this->assertDatabaseCount('code_chunks', 0);
    }

    public function test_chat_uses_bounded_untrusted_source_and_persists_citations_without_paid_inference(): void
    {
        $repo = $this->repository();
        $this->runIndex($repo);
        $this->project->update(['cloud_processing_allowed' => true]);
        $conversation = Conversation::create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'title' => 'Code']);
        $context = app(CodeContext::class)->build($conversation, $this->owner, 'renew_session', 6000);
        $this->assertStringContainsString('UNTRUSTED DATA', $context['messages'][0]['content']);
        $this->assertSame([], app(CodeContext::class)->build($conversation, $this->owner, 'renew_session', 256)['sources']);
        $this->app->instance(LLMProvider::class, new class implements LLMProvider
        {
            public function stream(array $messages, string $model, int $maxOutputTokens): iterable
            {
                $text = implode(' ', array_column($messages, 'content'));
                if (! str_contains($text, 'auth.ts') || ! str_contains($text, 'UNTRUSTED DATA')) {
                    throw new \RuntimeException('Missing source');
                }
                yield new ProviderEvent('delta', 'The indexed function refreshes the session.');
                yield new ProviderEvent('completed');
            }
        });
        $id = $this->postJson('/api/v1/conversations/'.$conversation->id.'/messages', ['content' => 'renew_session', 'model' => 'test-chat', 'idempotency_key' => (string) Str::uuid()])->assertAccepted()->json('data.id');
        app()->call([new GenerateChatResponse($id, 1), 'handle']);
        $message = Message::findOrFail($id);
        $this->assertSame('completed', $message->status);
        $this->assertSame('auth.ts', $message->code_sources[0]['path']);
    }

    public function test_archived_project_denies_configuration_and_snapshot_protocol_is_validated(): void
    {
        $repo = $this->repository();
        $this->project->update(['archived_at' => now()]);
        $this->postJson('/api/v1/repositories/'.$repo->id.'/index')->assertConflict();
        $this->postJson($this->url(), ['name' => 'New', 'path' => 'new'])->assertConflict();
        $this->project->update(['archived_at' => null]);
        $broken = $this->fixture();
        $broken['files'][0]['path'] = '../escape.py';
        $this->assertSame('failed', $this->runIndex($repo, $broken)->status);
        $this->assertDatabaseCount('code_chunks', 0);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer internal-test') && $request['project_id'] === $this->project->id && $request['path'] === 'repo');
    }

    public function test_recovery_or_deletion_during_embedding_cannot_publish_late_results(): void
    {
        $repo = $this->repository();
        $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embed(string $text): EmbeddingResult
            {
                IndexingJob::where('status', 'indexing')->update(['status' => 'failed']);

                return new EmbeddingResult([1., 0., 0.], 'test', 'test-v1', str_repeat('a', 64), 'utf8-384-mean-v1');
            }
        });
        $this->assertSame('failed', $this->runIndex($repo)->status);
        $this->assertDatabaseCount('code_chunks', 0);
    }
}

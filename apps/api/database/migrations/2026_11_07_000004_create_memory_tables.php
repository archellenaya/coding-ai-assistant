<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_summaries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('through_sequence');
            $t->enum('status', ['queued', 'processing', 'ready', 'failed']);
            $t->text('content')->nullable();
            $t->string('model');
            $t->unsignedInteger('attempt')->default(1);
            $t->json('usage')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'through_sequence']);
            $t->index(['status', 'updated_at']);
        });
        Schema::create('memories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignUuid('source_message_id')->nullable()->constrained('messages')->cascadeOnDelete();
            $t->foreignUuid('summary_id')->nullable()->constrained('conversation_summaries')->cascadeOnDelete();
            $t->enum('category', ['conversation', 'project', 'error', 'solution']);
            $t->text('content');
            $t->string('content_hash', 64);
            $t->string('source_kind');
            $t->string('source_key', 180);
            $t->json('provenance');
            $t->enum('verification_status', ['unverified'])->default('unverified');
            $t->enum('state', ['active', 'invalidated'])->default('active');
            $t->unsignedInteger('revision')->default(1);
            $t->enum('index_status', ['pending', 'indexing', 'ready', 'failed', 'none'])->default('pending');
            $t->uuid('index_token')->nullable();
            $t->string('index_error')->nullable();
            $t->string('error_signature', 180)->nullable();
            $t->string('git_commit', 64)->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'user_id', 'source_key']);
            $t->index(['project_id', 'user_id', 'state']);
            $t->index(['index_status', 'updated_at']);
        });
        Schema::create('memory_embeddings', function (Blueprint $t) {
            $t->foreignUuid('memory_id')->primary()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->string('profile', 64);
            $t->string('model');
            $t->string('model_version');
            $t->unsignedInteger('dimensions');
            $t->string('artifact_fingerprint', 64);
            $t->string('pooling');
            $t->json('values');
            $t->timestamps();
            $t->index(['profile', 'dimensions']);
        });
        Schema::table('messages', fn (Blueprint $t) => $t->json('memory_sources')->nullable());
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE memory_embeddings ADD COLUMN embedding vector');
            DB::statement('ALTER TABLE memory_embeddings ADD CONSTRAINT memory_vector_dimensions CHECK (embedding IS NULL OR vector_dims(embedding) = dimensions)');
            DB::statement("ALTER TABLE memories ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED");
            DB::statement('CREATE INDEX memories_search_vector_gin ON memories USING gin(search_vector)');
        }
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('memory_sources'));
        Schema::dropIfExists('memory_embeddings');
        Schema::dropIfExists('memories');
        Schema::dropIfExists('conversation_summaries');
    }
};

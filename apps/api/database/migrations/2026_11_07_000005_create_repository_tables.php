<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('path', 240);
            $t->string('revision', 64)->nullable();
            $t->string('parser_version')->nullable();
            $t->string('profile', 64)->nullable();
            $t->json('languages')->nullable();
            $t->json('frameworks')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'path']);
        });
        Schema::create('indexing_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->enum('status', ['queued', 'indexing', 'ready', 'failed']);
            $t->string('error_code')->nullable();
            $t->json('stats')->nullable();
            $t->timestamps();
            $t->index(['repository_id', 'status']);
        });
        Schema::create('repository_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $t->string('path', 500);
            $t->string('language', 30);
            $t->string('parser', 30);
            $t->string('content_hash', 64);
            $t->timestamps();
            $t->unique(['repository_id', 'path']);
        });
        Schema::create('code_chunks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('repository_file_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('ordinal');
            $t->string('symbol', 500)->nullable();
            $t->unsignedInteger('start_line');
            $t->unsignedInteger('end_line');
            $t->text('content');
            $t->string('profile', 64);
            $t->string('model');
            $t->string('model_version');
            $t->unsignedInteger('dimensions');
            $t->string('artifact_fingerprint', 64);
            $t->string('pooling');
            $t->json('values');
            $t->timestamps();
            $t->unique(['repository_file_id', 'ordinal']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE code_chunks ADD COLUMN embedding vector');
            DB::statement('ALTER TABLE code_chunks ADD CONSTRAINT code_embedding_dimensions CHECK (embedding IS NULL OR vector_dims(embedding) = dimensions)');
            DB::statement("ALTER TABLE code_chunks ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(symbol, '') || ' ' || content)) STORED");
            DB::statement('CREATE INDEX code_chunks_search_idx ON code_chunks USING GIN(search_vector)');
        }
        Schema::table('messages', fn (Blueprint $t) => $t->json('code_sources')->nullable());
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('code_sources'));
        Schema::dropIfExists('code_chunks');
        Schema::dropIfExists('repository_files');
        Schema::dropIfExists('indexing_jobs');
        Schema::dropIfExists('repositories');
    }
};

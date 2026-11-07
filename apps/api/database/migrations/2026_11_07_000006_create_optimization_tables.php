<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 30)->unique();
            $t->boolean('enabled');
            $t->timestamps();
        });
        Schema::create('ai_models', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('ai_provider_id')->constrained()->cascadeOnDelete();
            $t->string('identifier', 150)->unique();
            $t->boolean('active');
            $t->json('configuration');
            $t->timestamps();
        });
        Schema::table('conversations', function (Blueprint $t) {
            $t->enum('provider_mode', ['ollama', 'openai', 'auto'])->default('openai');
            $t->string('preferred_model', 150)->nullable();
            $t->boolean('allow_fallback')->default(false);
        });
        Schema::table('messages', function (Blueprint $t) {
            $t->json('routing')->nullable();
            $t->json('context_metrics')->nullable();
            $t->string('request_hash', 64)->nullable();
        });
        Schema::table('conversation_summaries', function (Blueprint $t) {
            $t->string('provider')->default('openai');
            $t->json('routing')->nullable();
        });
        Schema::create('model_calls', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('source_type', 20);
            $t->uuid('source_id');
            $t->unsignedInteger('attempt');
            $t->string('provider', 30);
            $t->string('model', 150);
            $t->enum('status', ['reserved', 'completed', 'failed', 'uncertain']);
            $t->foreignUuid('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('input_tokens')->nullable();
            $t->unsignedBigInteger('output_tokens')->nullable();
            $t->unsignedBigInteger('cached_tokens')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('error_code')->nullable();
            $t->json('pricing');
            $t->json('context_metrics')->nullable();
            $t->boolean('cache_hit')->default(false);
            $t->timestamps();
            $t->unique(['source_type', 'source_id', 'attempt']);
            $t->index(['organization_id', 'created_at']);
            $t->index(['project_id', 'created_at']);
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('usage_ledgers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('model_call_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('reserved_micro_usd');
            $t->unsignedBigInteger('estimated_micro_usd')->nullable();
            $t->timestamps();
        });
        Schema::create('solutions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('error_signature', 180);
            $t->text('content');
            $t->json('compatibility');
            $t->string('compatibility_hash', 64);
            $t->json('evidence');
            $t->enum('state', ['active', 'invalidated'])->default('active');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->index(['project_id', 'user_id', 'error_signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solutions');
        Schema::dropIfExists('usage_ledgers');
        Schema::dropIfExists('model_calls');
        Schema::table('conversation_summaries', fn (Blueprint $t) => $t->dropColumn(['provider', 'routing']));
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn(['routing', 'context_metrics', 'request_hash']));
        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn(['provider_mode', 'preferred_model', 'allow_fallback']));
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};

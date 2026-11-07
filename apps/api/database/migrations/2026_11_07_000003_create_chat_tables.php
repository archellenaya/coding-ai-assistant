<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->timestamps();
            $t->index(['project_id', 'user_id', 'updated_at']);
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('sequence');
            $t->enum('role', ['user', 'assistant']);
            $t->enum('status', ['queued', 'streaming', 'completed', 'failed']);
            $t->text('content');
            $t->uuid('idempotency_key')->nullable();
            $t->uuid('reply_to_id')->nullable()->unique();
            $t->string('provider')->nullable();
            $t->string('model')->nullable();
            $t->unsignedInteger('attempt')->default(1);
            $t->json('usage')->nullable();
            $t->string('provider_response_id')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'sequence']);
            $t->unique(['conversation_id', 'idempotency_key']);
            $t->index(['status', 'updated_at']);
        });
        // Add a self-reference after PostgreSQL has created the messages primary key.
        Schema::table('messages', function (Blueprint $t) {
            $t->foreign('reply_to_id')->references('id')->on('messages')->cascadeOnDelete();
        });
        Schema::create('message_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('attempt')->default(1);
            $t->string('type');
            $t->json('payload');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['message_id', 'attempt', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};

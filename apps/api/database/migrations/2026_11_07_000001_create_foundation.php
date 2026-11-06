<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
        Schema::create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('organization_members', function (Blueprint $t) {
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->enum('role', ['owner', 'member'])->default('member');
            $t->primary(['organization_id', 'user_id']);
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('cloud_processing_allowed')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
        });
        Schema::create('project_members', function (Blueprint $t) {
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['project_members', 'projects', 'organization_members', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

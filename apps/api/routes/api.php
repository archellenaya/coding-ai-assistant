<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\SummaryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'user']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('organizations', [ProjectController::class, 'organizations']);
        Route::apiResource('projects', ProjectController::class);
        Route::get('projects/{project}/memories', [MemoryController::class, 'index']);
        Route::post('projects/{project}/memories', [MemoryController::class, 'store']);
        Route::post('projects/{project}/memories/search', [MemoryController::class, 'search'])->middleware('throttle:memory');
        Route::get('memories/{memory}', [MemoryController::class, 'show']);
        Route::patch('memories/{memory}', [MemoryController::class, 'update']);
        Route::delete('memories/{memory}', [MemoryController::class, 'destroy']);
        Route::post('memories/{memory}/invalidate', [MemoryController::class, 'invalidate']);
        Route::post('memories/{memory}/reindex', [MemoryController::class, 'reindex']);
        Route::get('conversations/{conversation}/summary', [SummaryController::class, 'show']);
        Route::post('conversations/{conversation}/summary', [SummaryController::class, 'store'])->middleware('throttle:summary');
        Route::get('projects/{project}/repositories', [RepositoryController::class, 'index']);
        Route::post('projects/{project}/repositories', [RepositoryController::class, 'store']);
        Route::post('projects/{project}/code/search', [RepositoryController::class, 'search'])->middleware('throttle:memory');
        Route::get('repositories/{repository}', [RepositoryController::class, 'show']);
        Route::delete('repositories/{repository}', [RepositoryController::class, 'destroy']);
        Route::get('repositories/{repository}/files', [RepositoryController::class, 'files']);
        Route::post('repositories/{repository}/index', [RepositoryController::class, 'enqueue'])->middleware('throttle:repository');
        Route::get('code-chunks/{codeChunk}', [RepositoryController::class, 'chunk']);
        Route::get('models', [ConversationController::class, 'models']);
        Route::get('projects/{project}/conversations', [ConversationController::class, 'index']);
        Route::post('projects/{project}/conversations', [ConversationController::class, 'store']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
        Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send'])->middleware('throttle:chat');
        Route::post('conversations/{conversation}/messages/{message}/retry', [ConversationController::class, 'retry'])->middleware('throttle:chat');
        Route::get('conversations/{conversation}/messages/{message}/stream', [ConversationController::class, 'stream']);
    });
});

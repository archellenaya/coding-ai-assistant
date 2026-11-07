<?php

use App\Http\Middleware\AuditMutations;
use App\Services\AI\ProviderException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trimStrings(except: ['content']);
        $middleware->appendToGroup('api', AuditMutations::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ProviderException::class]);
        $exceptions->render(fn (ProviderException $e) => response()->json(['message' => match ($e->errorCode) {
            'budget_exceeded' => 'Daily AI spending limit reached. Check usage and server budget settings.','input_limit' => 'Prompt cannot fit the selected context window. Choose a larger model or shorten the prompt; no text was truncated.','tokenizer_unavailable' => 'Local tokenization is unavailable. Check the intelligence service.','provider_unavailable','no_authorized_model' => 'No selected authorized model is available. Refresh provider status.','invalid_model_configuration' => 'Server model configuration is invalid.',default => 'AI request unavailable. Check provider availability, privacy, token and spending limits.'
        }, 'code' => $e->errorCode], 503));
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })->create();

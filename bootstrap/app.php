<?php

use App\Http\Middleware\AuthenticateClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['client.auth' => AuthenticateClient::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ThrottleRequestsException) {
                return response()->json(['error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.']], 429, $exception->getHeaders());
            }

            if ($exception instanceof HttpExceptionInterface) {
                return response()->json(['error' => ['code' => 'INVALID_REQUEST', 'message' => 'Request could not be completed.']], $exception->getStatusCode());
            }

            return response()->json(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal server error.']], 500);
        });
    })->create();

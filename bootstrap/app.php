<?php

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
        $middleware->alias([
            // Custom role middleware — checks the `role` column (single source of truth).
            // Replaces Spatie's RoleMiddleware so routes never break when model_has_roles
            // pivot is out of sync with the role column.
            'role'       => \App\Http\Middleware\RequireRole::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $isApi = fn (\Illuminate\Http\Request $r) => $r->is('api/*') || $r->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // ── Validation errors: 422 ────────────────────────────────────────────
        $exceptions->render(function (
            \Illuminate\Validation\ValidationException $e,
            \Illuminate\Http\Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) return null;

            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        });

        // ── Auth/authz errors ─────────────────────────────────────────────────
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            \Illuminate\Http\Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) return null;

            return response()->json([
                'message' => 'Unauthenticated.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        });

        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e,
            \Illuminate\Http\Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) return null;

            return response()->json([
                'message' => $e->getMessage() ?: 'Forbidden.',
                'code'    => 'FORBIDDEN',
            ], 403);
        });

        // ── 404 ──────────────────────────────────────────────────────────────
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e,
            \Illuminate\Http\Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) return null;

            return response()->json([
                'message' => 'Resource not found.',
                'code'    => 'NOT_FOUND',
            ], 404);
        });

        // ── Generic HTTP exceptions ────────────────────────────────────────────
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\HttpException $e,
            \Illuminate\Http\Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) return null;

            return response()->json([
                'message' => $e->getMessage() ?: 'HTTP error.',
                'code'    => 'HTTP_ERROR',
            ], $e->getStatusCode());
        });

        // ── Catch-all ─────────────────────────────────────────────────────────
        $exceptions->render(function (
            \Throwable $e,
            \Illuminate\Http\Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) return null;
            if (app()->hasDebugModeEnabled()) return null; // let Laravel render with stack trace

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'code'    => 'INTERNAL_ERROR',
            ], 500);
        });

    })->create();

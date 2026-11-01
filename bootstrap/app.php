<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            \App\Http\Middleware\SetApiLanguage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // Let default handler handle web requests
            }

            // 1. Validation Errors
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return \App\Helpers\ApiResponder::error(
                    __('Validation failed'),
                    $e->errors(),
                    422
                );
            }

            // 2. Authentication Errors (401)
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $errMsg = $e->getMessage() === 'Unauthenticated.' || $e->getMessage() === 'Unauthenticated' ? 'Unauthorized' : $e->getMessage();
                return \App\Helpers\ApiResponder::error(
                    __($errMsg),
                    [],
                    401
                );
            }

            // 2.5 Authz (403)
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException || $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
                 return \App\Helpers\ApiResponder::error(
                    __('You are not allowed to perform this action'),
                    [],
                    403
                );
            }

            // 3. Not Found (404)
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return \App\Helpers\ApiResponder::error(
                    __('Resource not found'),
                    [],
                    404
                );
            }

            // 4. General Server Error (500)
            $message = app()->hasDebugModeEnabled() ? $e->getMessage() : __('Something went wrong');
            
            return \App\Helpers\ApiResponder::error(
                $message,
                app()->hasDebugModeEnabled() ? ['trace' => $e->getTrace()] : [],
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getStatusCode() : 500
            );
        });
    })->create();

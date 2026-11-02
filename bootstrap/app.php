<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\SetApiLanguage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:api']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetApiLanguage::class,
        ]);
        $middleware->throttleApi();

        // Check signatures before route model binding, so a tampered URL never reveals
        // whether a record exists.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ValidateSignature::class,
        );

        $middleware->alias([
            'user.type' => EnsureUserType::class,
            'account.active' => EnsureAccountActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        // API clients never get redirected to a login page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(new ApiExceptionRenderer);
    })->create();

<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Helpers\ApiResponder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns every exception on an API request into the error envelope with a stable `code`.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof ApiException => ApiResponder::error($e->errorCode, $e->getMessage(), $e->errors, $e->status()),
            $e instanceof ValidationException => ApiResponder::error(ErrorCode::ValidationFailed, null, $e->errors()),
            $e instanceof AuthenticationException => ApiResponder::error($this->authenticationCode($request)),
            $e instanceof AuthorizationException => ApiResponder::error(
                $e->hasStatus() && $e->status() === 404 ? ErrorCode::NotFound : ErrorCode::Forbidden
            ),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponder::error(ErrorCode::NotFound),
            $e instanceof MethodNotAllowedHttpException => ApiResponder::error(ErrorCode::MethodNotAllowed),
            $e instanceof ThrottleRequestsException => ApiResponder::error(ErrorCode::TooManyAttempts),
            $e instanceof HttpExceptionInterface => $this->httpError($e),
            default => ApiResponder::error(ErrorCode::ServerError, debug: $this->debug($e)),
        };
    }

    /**
     * The JWT guard swallows token errors and reports "unauthenticated"; re-check to tell an
     * expired token apart so the apps know to refresh.
     */
    private function authenticationCode(Request $request): ErrorCode
    {
        if (! $request->bearerToken()) {
            return ErrorCode::Unauthenticated;
        }

        try {
            auth('api')->checkOrFail();
        } catch (TokenExpiredException) {
            return ErrorCode::TokenExpired;
        } catch (Throwable) {
            // Invalid, blacklisted or malformed tokens are plain "unauthenticated".
        }

        return ErrorCode::Unauthenticated;
    }

    private function httpError(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = ErrorCode::fromStatus($status);

        return ApiResponder::error($code, status: $status, debug: $code === ErrorCode::ServerError ? $this->debug($e) : []);
    }

    private function debug(Throwable $e): array
    {
        if (! app()->hasDebugModeEnabled()) {
            return [];
        }

        return [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => collect($e->getTrace())->take(15)->map(
                fn (array $frame) => ($frame['file'] ?? '?').':'.($frame['line'] ?? '?')
            )->all(),
        ];
    }
}

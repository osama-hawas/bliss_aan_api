<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOptionally
{
    public function __construct(
        private readonly Authenticate $authenticate,
        private readonly EnsureDeviceSession $deviceSession,
    ) {}

    /**
     * Public routes that personalize the answer (e.g. `is_favorite`). Without a token the request is a
     * guest; a token, when sent, must be valid like on any authenticated route (401 → refresh).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            return $next($request);
        }

        return $this->authenticate->handle($request, fn (Request $request) => $this->deviceSession->handle($request, $next), 'api');
    }
}

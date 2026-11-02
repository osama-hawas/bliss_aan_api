<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    /**
     * Allow only the given user types, e.g. `user.type:client` or `user.type:client,provider`.
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $userType = $request->user()?->type;

        if (! $userType instanceof UserType || ! in_array($userType->value, $types, true)) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    /**
     * Suspended accounts get 403 ACCOUNT_SUSPENDED with the user-visible reason.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->status === UserStatus::Suspended) {
            throw new ApiException(
                ErrorCode::AccountSuspended,
                errors: ['reason' => $user->status_reason],
            );
        }

        return $next($request);
    }
}

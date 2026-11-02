<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\UserDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceSession
{
    /**
     * How often `last_seen_at` is written, to avoid one UPDATE per request.
     */
    private const TOUCH_EVERY_SECONDS = 300;

    /**
     * Runs after `auth:api`. The token's `jti` must belong to an open user_devices row,
     * so "log out other devices" takes effect immediately. The row is shared as `device`.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $device = $user ? UserDevice::query()
            ->where('jti', auth('api')->payload()->get('jti'))
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first() : null;

        if (! $device) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }

        if (! $device->last_seen_at || $device->last_seen_at->diffInSeconds(now()) >= self::TOUCH_EVERY_SECONDS) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}

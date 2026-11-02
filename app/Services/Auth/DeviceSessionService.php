<?php

namespace App\Services\Auth;

use App\Enums\DeviceApp;
use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Token;

/**
 * One user_devices row per issued token (keyed by the JWT `jti`). The row is the session:
 * revoking it signs that device out, even though the token itself is still unexpired.
 */
class DeviceSessionService
{
    /**
     * @param  array{platform?: ?string, device_name?: ?string, fcm_token?: ?string}  $device
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function issue(User $user, DeviceApp $app, array $device = []): array
    {
        $token = JWTAuth::fromUser($user);

        UserDevice::query()->create([
            'user_id' => $user->id,
            'jti' => $this->jti($token),
            'app' => $app,
            'platform' => $device['platform'] ?? null,
            'device_name' => $device['device_name'] ?? null,
            'fcm_token' => $device['fcm_token'] ?? null,
            'last_seen_at' => now(),
        ]);

        return $this->tokenPayload($token);
    }

    /**
     * Exchanges a token (expired is fine, inside the refresh window) for a new one on the
     * same device. The old token is blacklisted.
     *
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function refresh(string $token): array
    {
        $manager = JWTAuth::manager();

        try {
            $payload = $manager->setRefreshFlow()->decode(new Token($token));

            return DB::transaction(function () use ($manager, $payload, $token) {
                $device = UserDevice::query()
                    ->where('jti', $payload->get('jti'))
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();

                if (! $device || (string) $device->user_id !== (string) $payload->get('sub')) {
                    throw new ApiException(ErrorCode::Unauthenticated);
                }

                $user = User::query()->find($device->user_id) ?? throw new ApiException(ErrorCode::Unauthenticated);

                if ($user->status === UserStatus::Suspended) {
                    throw new ApiException(ErrorCode::AccountSuspended, errors: ['reason' => $user->status_reason]);
                }

                $newToken = $manager->refresh(new Token($token))->get();
                $device->update(['jti' => $this->jti($newToken), 'last_seen_at' => now()]);

                return $this->tokenPayload($newToken);
            });
        } catch (JWTException) {
            // Past the refresh window, blacklisted or malformed: the user must log in again.
            throw new ApiException(ErrorCode::Unauthenticated);
        } finally {
            // The manager is a singleton; leaving refresh mode on would accept expired tokens later.
            $manager->setRefreshFlow(false);
        }
    }

    /**
     * Logs out the current token: blacklist it and close its device row.
     */
    public function logout(UserDevice $device): void
    {
        auth('api')->invalidate();

        $this->revoke(UserDevice::query()->whereKey($device->id));
    }

    /**
     * @return int how many sessions were closed
     */
    public function logoutOthers(UserDevice $current): int
    {
        return $this->revoke(
            UserDevice::query()->where('user_id', $current->user_id)->whereKeyNot($current->id)
        );
    }

    public function setFcmToken(UserDevice $device, ?string $fcmToken): UserDevice
    {
        $device->update(['fcm_token' => $fcmToken]);

        return $device;
    }

    private function revoke($query): int
    {
        // Revoked sessions must not receive pushes either.
        return $query->whereNull('revoked_at')->update(['revoked_at' => now(), 'fcm_token' => null]);
    }

    private function jti(string $token): string
    {
        return JWTAuth::manager()->decode(new Token($token), false)->get('jti');
    }

    private function tokenPayload(string $token): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
        ];
    }
}

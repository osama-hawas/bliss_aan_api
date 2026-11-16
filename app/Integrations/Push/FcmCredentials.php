<?php

namespace App\Integrations\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Firebase service-account credentials (D139): the project id and a short-lived OAuth token for FCM HTTP v1.
 * Configured by FCM_CREDENTIALS (path to the service-account JSON, never committed) and FCM_PROJECT_ID (optional,
 * defaults to the JSON's project_id). Nothing here is a default value: without configuration it refuses.
 */
class FcmCredentials
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function projectId(): string
    {
        $id = config('services.push.fcm.project_id') ?: ($this->json()['project_id'] ?? null);

        return is_string($id) && $id !== '' ? $id : throw new RuntimeException('FCM project id is not configured.');
    }

    public function accessToken(): string
    {
        return Cache::remember('fcm:access-token', now()->addMinutes(50), function () {
            $token = (new ServiceAccountCredentials(self::SCOPE, $this->json()))->fetchAuthToken();

            return $token['access_token'] ?? throw new RuntimeException('FCM access token was not issued.');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $path = config('services.push.fcm.credentials');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw new RuntimeException('FCM credentials are not configured (FCM_CREDENTIALS).');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}

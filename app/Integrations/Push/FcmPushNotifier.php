<?php

namespace App\Integrations\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FCM HTTP v1 driver (`PUSH_DRIVER=fcm`, D139). Maps FCM answers to PushResult; never throws.
 */
class FcmPushNotifier implements PushNotifier
{
    private const INVALID_TOKEN_CODES = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    private const RETRY_CODES = ['UNAVAILABLE', 'INTERNAL', 'QUOTA_EXCEEDED'];

    public function __construct(private readonly FcmCredentials $credentials) {}

    public function send(PushMessage $message): PushResult
    {
        try {
            $url = 'https://fcm.googleapis.com/v1/projects/'.$this->credentials->projectId().'/messages:send';
            $token = $this->credentials->accessToken();
        } catch (Throwable $e) {
            Log::error('[fcm] not configured or no access token', ['error' => $e->getMessage()]);

            return PushResult::Retry;
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->post($url, ['message' => [
                'token' => $message->token,
                'notification' => ['title' => $message->title, 'body' => $message->body],
                'data' => $message->data,
                'android' => ['priority' => 'high', 'collapse_key' => $message->collapseKey, 'notification' => ['tag' => $message->collapseKey]],
                'apns' => ['headers' => ['apns-collapse-id' => $message->collapseKey]],
            ]]);
        } catch (ConnectionException $e) {
            Log::warning('[fcm] connection failed', ['error' => $e->getMessage()]);

            return PushResult::Retry;
        }

        if ($response->successful()) {
            return PushResult::Sent;
        }

        $status = $response->status();
        $error = (array) $response->json('error', []);
        $code = collect($error['details'] ?? [])->pluck('errorCode')->filter()->first() ?? ($error['status'] ?? null);
        $text = (string) ($error['message'] ?? '');

        if (in_array($code, self::INVALID_TOKEN_CODES, true)
            || ($code === 'INVALID_ARGUMENT' && str_contains(strtolower($text), 'registration token'))) {
            return PushResult::InvalidToken;
        }
        if ($status === 429 || $status >= 500 || in_array($code, self::RETRY_CODES, true)) {
            return PushResult::Retry;
        }

        Log::error('[fcm] push refused', ['status' => $status, 'code' => $code, 'message' => $text]);

        return PushResult::Failed;
    }
}

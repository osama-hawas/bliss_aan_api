<?php

namespace App\Integrations\Push;

/**
 * Push delivery (FCM, D139). Device tokens come from user_devices.fcm_token. One call = one token, so the caller can
 * clear an invalid token and retry a temporary failure for that device only.
 */
interface PushNotifier
{
    public function send(PushMessage $message): PushResult;
}

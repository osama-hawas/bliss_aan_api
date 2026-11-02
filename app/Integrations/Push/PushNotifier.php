<?php

namespace App\Integrations\Push;

/**
 * Push delivery (FCM recommended). Device tokens come from user_devices.fcm_token.
 */
interface PushNotifier
{
    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data  deep-link payload; apps open the live state
     */
    public function send(array $tokens, string $title, string $body, array $data = []): void;
}

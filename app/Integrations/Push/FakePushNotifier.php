<?php

namespace App\Integrations\Push;

use Illuminate\Support\Facades\Log;

/**
 * Local and test driver: nothing leaves the server. Tests may script a result per token.
 */
class FakePushNotifier implements PushNotifier
{
    /** @var list<PushMessage> */
    public array $sent = [];

    /** @var array<string, PushResult> token => result */
    public array $results = [];

    public function send(PushMessage $message): PushResult
    {
        $this->sent[] = $message;

        Log::info('[fake-push] notification', ['event' => $message->data['event'] ?? null]);

        return $this->results[$message->token] ?? PushResult::Sent;
    }
}

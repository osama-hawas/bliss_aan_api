<?php

namespace App\Integrations\Push;

use Illuminate\Support\Facades\Log;

class FakePushNotifier implements PushNotifier
{
    /** @var list<array{tokens: list<string>, title: string, body: string, data: array}> */
    public array $sent = [];

    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        $this->sent[] = compact('tokens', 'title', 'body', 'data');

        Log::info('[fake-push] notification', ['tokens' => count($tokens), 'title' => $title]);
    }
}

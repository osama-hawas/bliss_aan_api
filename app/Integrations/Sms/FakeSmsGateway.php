<?php

namespace App\Integrations\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Logs messages instead of sending them and keeps them in memory for tests.
 */
class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{phone: string, message: string}> */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];

        Log::info('[fake-sms] message', ['phone' => $phone, 'message' => $message]);
    }
}

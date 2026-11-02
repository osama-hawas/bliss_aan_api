<?php

namespace App\Integrations\Sms;

/**
 * SMS vendor is undecided (Q8). Real drivers implement this interface.
 */
interface SmsGateway
{
    public function send(string $phone, string $message): void;
}

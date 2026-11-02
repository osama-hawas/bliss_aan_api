<?php

namespace Tests\Concerns;

use App\Enums\OtpPurpose;
use App\Integrations\Sms\SmsGateway;
use App\Models\OtpCode;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\Hash;

trait InteractsWithOtp
{
    protected function configureOtp(int $expiry = 300, int $maxAttempts = 3, int $cooldown = 60): void
    {
        $settings = app(AppSettings::class);
        $settings->set('otp.length', 4);
        $settings->set('otp.expiry_seconds', $expiry);
        $settings->set('otp.max_attempts', $maxAttempts);
        $settings->set('otp.resend_cooldown_seconds', $cooldown);
    }

    protected function plantOtp(string $phone, OtpPurpose $purpose, string $code = '1234', array $attributes = []): OtpCode
    {
        return OtpCode::query()->create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
            ...$attributes,
        ]);
    }

    /**
     * @return list<array{phone: string, message: string}>
     */
    protected function sentSms(): array
    {
        return app(SmsGateway::class)->sent;
    }

    protected function lastSentCode(): ?string
    {
        $sent = $this->sentSms();
        $last = end($sent) ?: null;

        return $last && preg_match('/\d{4,}/', $last['message'], $m) ? $m[0] : null;
    }
}

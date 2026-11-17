<?php

namespace App\Services\Payments;

use App\Services\Settings\AppSettings;

/**
 * Slice 10 settings (app_settings): the booking payment flag (D185) and the unpaid attempt lifetime (D191).
 */
class PaymentSettings
{
    public const BOOKING_PAYMENT_REQUIRED = 'payments.booking_payment_required';

    public const INTENT_TTL_MINUTES = 'payments.intent_ttl_minutes';

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * Missing / null = false: today's D113 behaviour.
     */
    public function bookingPaymentRequired(): bool
    {
        return (bool) $this->settings->get(self::BOOKING_PAYMENT_REQUIRED, false);
    }

    /**
     * Null = unpaid attempts never expire on our side.
     */
    public function intentTtlMinutes(): ?int
    {
        $value = $this->settings->get(self::INTENT_TTL_MINUTES);

        return $value === null ? null : max(1, (int) $value);
    }
}

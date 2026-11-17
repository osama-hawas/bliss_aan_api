<?php

namespace App\Integrations\Payments;

final readonly class WebhookEvent
{
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const PAYMENT_FAILED = 'payment.failed';

    public const PAYMENT_CANCELLED = 'payment.cancelled';

    public const REFUND_SUCCEEDED = 'refund.succeeded';

    public const REFUND_FAILED = 'refund.failed';

    /**
     * @param  string|null  $gatewayReference  the charge reference for payment.*, the refund reference for refund.*
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $gatewayReference,
        public ?string $failureCode,
        public array $raw,
    ) {}

    public function isPaymentEvent(): bool
    {
        return in_array($this->type, [self::PAYMENT_SUCCEEDED, self::PAYMENT_FAILED, self::PAYMENT_CANCELLED], true);
    }

    public function isRefundEvent(): bool
    {
        return in_array($this->type, [self::REFUND_SUCCEEDED, self::REFUND_FAILED], true);
    }

    /**
     * The outcome as a gateway result (status from the event type).
     */
    public function toResult(): GatewayResult
    {
        $status = match ($this->type) {
            self::PAYMENT_SUCCEEDED, self::REFUND_SUCCEEDED => GatewayResult::SUCCEEDED,
            self::PAYMENT_FAILED, self::REFUND_FAILED => GatewayResult::FAILED,
            default => GatewayResult::CANCELLED,
        };

        return new GatewayResult($status, $this->gatewayReference, $this->raw, null, $this->failureCode);
    }
}

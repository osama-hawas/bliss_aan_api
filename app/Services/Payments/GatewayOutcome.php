<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\GatewayResult;

/**
 * A payment outcome from any source (charge result, webhook, reconcile), mapped to our status.
 */
final readonly class GatewayOutcome
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $reference = null,
        public ?string $failureCode = null,
        public ?string $actionUrl = null,
        public array $raw = [],
    ) {}

    public static function fromResult(GatewayResult $result): self
    {
        $status = match ($result->status) {
            GatewayResult::SUCCEEDED => PaymentStatus::Succeeded,
            GatewayResult::FAILED => PaymentStatus::Failed,
            GatewayResult::REQUIRES_ACTION => PaymentStatus::RequiresAction,
            GatewayResult::CANCELLED => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };

        return new self($status, $result->reference, $status === PaymentStatus::Failed ? ($result->failureCode ?? 'gateway_error') : null,
            $result->actionUrl, $result->raw);
    }

    public static function unknown(): self
    {
        return new self(PaymentStatus::Unknown);
    }

    public static function cancelled(): self
    {
        return new self(PaymentStatus::Cancelled);
    }
}

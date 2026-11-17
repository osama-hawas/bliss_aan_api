<?php

namespace App\Integrations\Payments;

final readonly class GatewayResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const REQUIRES_ACTION = 'requires_action';

    public const PENDING = 'pending';

    public const CANCELLED = 'cancelled';

    /**
     * @param  string  $status  succeeded | failed | requires_action | pending | cancelled
     * @param  string|null  $failureCode  declined | insufficient_funds | expired_card | authentication_failed | gateway_error
     */
    public function __construct(
        public string $status,
        public ?string $reference,
        public array $raw = [],
        public ?string $actionUrl = null,
        public ?string $failureCode = null,
    ) {}
}

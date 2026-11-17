<?php

namespace App\Integrations\Payments;

final readonly class ChargeRequest
{
    /**
     * @param  string  $amount  "5500.00"
     * @param  string  $reference  our payment reference (PY-…)
     */
    public function __construct(
        public string $amount,
        public string $currency,
        public string $token,
        public string $idempotencyKey,
        public string $reference,
        public string $method,
        public array $metadata = [],
    ) {}
}

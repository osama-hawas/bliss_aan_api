<?php

namespace App\Integrations\Payments;

final readonly class GatewayResult
{
    /**
     * @param  string  $status  pending | succeeded | failed | cancelled | unknown
     */
    public function __construct(
        public string $status,
        public ?string $reference,
        public array $raw = [],
    ) {}
}

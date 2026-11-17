<?php

namespace App\Services\Payments;

use App\Enums\PaymentPurpose;

/**
 * What a handler says the payable costs now (slice-10-contract.md §4.1).
 */
final readonly class PaymentQuote
{
    /**
     * @param  string  $amount  "5500.00", > 0
     */
    public function __construct(
        public string $amount,
        public PaymentPurpose $purpose,
        public string $description,
        public array $metadata = [],
    ) {}
}

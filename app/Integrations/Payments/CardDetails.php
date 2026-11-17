<?php

namespace App\Integrations\Payments;

final readonly class CardDetails
{
    public function __construct(
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
    ) {}

    /**
     * @return array{brand: string, last4: string, exp_month: int, exp_year: int}
     */
    public function toArray(): array
    {
        return ['brand' => $this->brand, 'last4' => $this->last4, 'exp_month' => $this->expMonth, 'exp_year' => $this->expYear];
    }
}

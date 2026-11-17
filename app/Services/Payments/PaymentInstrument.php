<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethodType;

/**
 * What the client pays with: one of her saved methods or a fresh gateway token. Never card data (D188).
 */
final readonly class PaymentInstrument
{
    public function __construct(
        public PaymentMethodType $type,
        public ?int $savedMethodId = null,
        public ?string $token = null,
        public bool $save = false,
    ) {}

    /**
     * Part of the idempotency fingerprint (the token itself is hashed, never stored here).
     */
    public function fingerprint(): string
    {
        return implode('|', [$this->type->value, $this->savedMethodId ?? '-', $this->token !== null ? hash('sha256', $this->token) : '-', $this->save ? '1' : '0']);
    }
}

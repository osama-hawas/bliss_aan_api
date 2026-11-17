<?php

namespace App\Services\Payments;

/**
 * `PY-` / `RF-` + 7 random digits. Display only; inserts retry on a collision.
 */
class PaymentReferenceGenerator
{
    public function payment(): string
    {
        return 'PY-'.$this->digits();
    }

    public function refund(): string
    {
        return 'RF-'.$this->digits();
    }

    private function digits(): string
    {
        return str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    }
}

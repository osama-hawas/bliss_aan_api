<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * The live total differs from the one she confirmed (D210): `409 PRICE_CHANGED` with the new preview as `data`
 * (rendered by the checkout controller).
 */
class PriceChanged extends ApiException
{
    public function __construct(public readonly CheckoutQuote $quote)
    {
        parent::__construct(ErrorCode::PriceChanged);
    }
}

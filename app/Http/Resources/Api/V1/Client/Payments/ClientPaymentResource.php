<?php

namespace App\Http\Resources\Api\V1\Client\Payments;

use App\Http\Resources\Concerns\DescribesPayment;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her own payment (slice-10-contract.md §10.1). The app switches on `status` + `fulfilment`. Also returned by S9b's
 * store-order payment endpoint. Load `refunds` and `booking` before passing it in.
 *
 * @mixin Payment
 */
class ClientPaymentResource extends JsonResource
{
    use DescribesPayment, FormatsApiValues;

    public function toArray(Request $request): array
    {
        return $this->paymentCore($this->resource);
    }
}

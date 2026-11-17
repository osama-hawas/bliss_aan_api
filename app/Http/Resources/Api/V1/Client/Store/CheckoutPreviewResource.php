<?php

namespace App\Http\Resources\Api\V1\Client\Store;

use App\Http\Resources\Api\V1\Client\Cart\CartResource;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Cart;
use App\Services\Store\CheckoutQuote;
use App\Services\Store\StoreMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The checkout preview (`slice-9b-contract.md` §6.1). Also the `data` of a `409 PRICE_CHANGED`.
 *
 * @property-read CheckoutQuote $resource
 */
class CheckoutPreviewResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        $cart = (new Cart)->setRelation('items', $quote->lines);
        $address = $quote->address;

        return [
            'lines' => (new CartResource($cart))->toArray($request)['items'],
            'price_changes' => array_map(fn (array $change) => [
                'variant_id' => $change['variant_id'],
                'name' => $change['name'],
                'previous_unit_price' => $this->money($change['previous_unit_price']),
                'unit_price' => $this->money($change['unit_price']),
            ], $quote->priceChanges),
            'subtotal' => $this->money(StoreMoney::amount($quote->subtotal)),
            'discount' => $this->money(StoreMoney::amount($quote->discount)),
            'promo' => $quote->promo ? ['code' => $quote->promo->code, 'type' => $quote->promo->type->value, 'value' => $quote->promo->value] : null,
            'shipping_fee' => $this->money(StoreMoney::amount($quote->shippingFee)),
            'vat' => $quote->vatPercent === null ? null : ['percent' => $quote->vatPercent, 'amount' => $this->money(StoreMoney::amount((int) $quote->vat))],
            'total' => $this->money($quote->totalAmount()),
            'address' => $address === null ? null : [
                'id' => $address->id,
                'label' => $address->label,
                'city' => $address->city ? ['id' => $address->city->id, 'name' => $address->city->name] : null,
                'district' => $address->district,
                'details' => $address->details,
                'recipient_name' => $address->recipient_name,
                'recipient_phone' => $address->recipient_phone,
            ],
            'can_checkout' => $address !== null,
        ];
    }
}

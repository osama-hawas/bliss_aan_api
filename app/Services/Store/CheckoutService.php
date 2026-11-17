<?php

namespace App\Services\Store;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\PromoCodeUsageStatus;
use App\Enums\StoreOrderStatus;
use App\Exceptions\ApiException;
use App\Models\CartItem;
use App\Models\PromoCodeUsage;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\ServiceRequests\IdempotencyRace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Checkout (`slice-9b-contract.md` §6.1–§6.2): the preview, and the order snapshot created as `pending_payment`.
 * Paying it is the Slice 10 call (`client/store-orders/{id}/payments`, D214). Nothing is reserved (D207).
 */
class CheckoutService
{
    private const REFERENCE_INDEX = 'store_orders_reference_unique';

    private const IDEMPOTENCY_INDEX = 'store_orders_user_id_idempotency_key_unique';

    public function __construct(
        private readonly CheckoutCalculator $calculator,
        private readonly StoreOrderLifecycle $lifecycle,
        private readonly StoreOrderReferenceGenerator $references,
        private readonly ActivityRecorder $activity,
        private readonly DeadlockRetry $retry,
    ) {}

    public function preview(User $client, ?int $addressId, ?string $promoCode): CheckoutQuote
    {
        return $this->calculator->quote($client, $addressId, $promoCode);
    }

    /**
     * @param  array{address_id: int|string, promo_code?: string|null, expected_total: string}  $data
     * @return array{0: StoreOrder, 1: bool} the order and whether it was created now (false = replay)
     */
    public function create(User $client, string $key, array $data): array
    {
        $fingerprint = $this->fingerprint($data);

        return $this->retry->run(function () use ($client, $key, $fingerprint, $data) {
            if ($existing = $this->replay($client, $key, $fingerprint)) {
                return [$existing, false];
            }

            try {
                return [DB::transaction(fn () => $this->store($client, $key, $fingerprint, $data)), true];
            } catch (IdempotencyRace) {
                return [$this->replay($client, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
            }
        }, 'store_order.create', ['user_id' => $client->id, 'idempotency_key' => $key]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function fingerprint(array $data): string
    {
        $promo = isset($data['promo_code']) && trim((string) $data['promo_code']) !== '' ? mb_strtoupper(trim((string) $data['promo_code'])) : null;

        return hash('sha256', json_encode([
            'address_id' => (int) $data['address_id'],
            'promo_code' => $promo,
            'expected_total' => StoreMoney::cents((string) $data['expected_total']),
        ]));
    }

    private function replay(User $client, string $key, string $fingerprint): ?StoreOrder
    {
        $existing = StoreOrder::query()->where('user_id', $client->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }

    /**
     * Lock order: client → (key re-check) → her other unpaid orders → cart lines → promo code.
     *
     * @param  array<string, mixed>  $data
     */
    private function store(User $client, string $key, string $fingerprint, array $data): StoreOrder
    {
        User::query()->whereKey($client->id)->lockForUpdate()->first(['id']);
        if (StoreOrder::query()->where('user_id', $client->id)->where('idempotency_key', $key)->lockForUpdate()->first(['id']) !== null) {
            throw new IdempotencyRace;
        }

        // One open checkout per client (D212/Q115): the newer one replaces her older unpaid orders. Rolled back with the
        // rest when this checkout is refused.
        StoreOrder::query()->where('user_id', $client->id)->where('status', StoreOrderStatus::PendingPayment)
            ->orderBy('id')->get()->each(fn (StoreOrder $old) => $this->lifecycle->expire($old));

        $quote = $this->calculator->quote($client, (int) $data['address_id'], $data['promo_code'] ?? null, lock: true);
        if ($quote->total !== StoreMoney::cents((string) $data['expected_total'])) {
            throw new PriceChanged($quote);
        }

        $address = $quote->address;
        $order = $this->insert([
            'user_id' => $client->id,
            'status' => StoreOrderStatus::PendingPayment,
            'address' => [
                'city_id' => $address->city_id,
                'city' => $address->city?->getTranslations('name'),
                'district' => $address->district,
                'details' => $address->details,
                'lat' => $address->lat,
                'lng' => $address->lng,
            ],
            'recipient_name' => $address->recipient_name ?: $client->name,
            'recipient_phone' => $address->recipient_phone ?: $client->phone,
            'subtotal' => StoreMoney::amount($quote->subtotal),
            'discount' => StoreMoney::amount($quote->discount),
            'shipping_fee' => StoreMoney::amount($quote->shippingFee),
            'total' => $quote->totalAmount(),
            'vat_percent' => $quote->vatPercent,
            'vat_amount' => $quote->vat === null ? null : StoreMoney::amount($quote->vat),
            'promo' => $quote->promo ? ['code' => $quote->promo->code, 'type' => $quote->promo->type->value, 'value' => $quote->promo->value] : null,
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
        ]);

        $quote->lines->each(function (CartItem $line) use ($order) {
            $variant = $line->variant;
            $unit = StoreMoney::cents($variant->price);
            StoreOrderItem::query()->create([
                'store_order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->product->getTranslations('name'),
                'options' => $variant->getAttribute('attributes'),
                'sku' => $variant->sku,
                'unit_price' => $variant->price,
                'quantity' => $line->quantity,
                'line_total' => StoreMoney::amount($unit * $line->quantity),
            ]);
        });

        if ($quote->promo !== null) {
            PromoCodeUsage::query()->create([
                'promo_code_id' => $quote->promo->id,
                'user_id' => $client->id,
                'store_order_id' => $order->id,
                'status' => PromoCodeUsageStatus::Held,
            ]);
        }

        $this->activity->record($order, 'order_placed', null, StoreOrderStatus::PendingPayment, $client, ActivityVisibility::Internal);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): StoreOrder
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return StoreOrder::query()->create(['reference' => $this->references->next(), ...$attributes]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                    throw new IdempotencyRace;
                }
                if (! str_contains($e->getMessage(), self::REFERENCE_INDEX) || $attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}

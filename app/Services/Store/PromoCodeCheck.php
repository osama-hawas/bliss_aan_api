<?php

namespace App\Services\Store;

use App\Enums\ErrorCode;
use App\Enums\PromoCodeType;
use App\Enums\PromoCodeUsageStatus;
use App\Exceptions\ApiException;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\User;

/**
 * Whether a promo code can be used now by her on a products subtotal, and the discount (D200, D213). One reason per
 * refusal, as `422 PROMO_CODE_INVALID` with `errors.promo_code`.
 */
class PromoCodeCheck
{
    /**
     * @param  bool  $lock  lock the promo row (checkout): limits are counted under it, so the last use can't be taken twice
     * @return array{0: PromoCode, 1: int} the code and the discount in cents
     */
    public function evaluate(string $code, User $client, int $subtotalCents, bool $lock = false, ?int $exceptOrderId = null): array
    {
        $query = PromoCode::query()->where('code', PromoCode::normalize($code));
        $promo = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($promo === null || ! $promo->is_active) {
            $this->refuse('unknown');
        }
        if ($promo->starts_at !== null && $promo->starts_at->isFuture()) {
            $this->refuse('not_started');
        }
        if ($promo->ends_at !== null && $promo->ends_at->isPast()) {
            $this->refuse('ended');
        }

        $uses = PromoCodeUsage::query()->where('promo_code_id', $promo->id)
            ->whereIn('status', PromoCodeUsageStatus::counting())
            ->when($exceptOrderId, fn ($q, $id) => $q->where('store_order_id', '!=', $id));
        if ($promo->usage_limit !== null && (clone $uses)->count() >= $promo->usage_limit) {
            $this->refuse('usage_limit');
        }
        if ($promo->per_user_limit !== null && (clone $uses)->where('user_id', $client->id)->count() >= $promo->per_user_limit) {
            $this->refuse('user_limit');
        }
        if ($promo->min_amount !== null && $subtotalCents < StoreMoney::cents($promo->min_amount)) {
            $this->refuse('min_amount', ['amount' => $promo->min_amount]);
        }

        return [$promo, $this->discount($promo, $subtotalCents)];
    }

    /** Percent: round half up of the subtotal share; fixed: never more than the subtotal (D213: products subtotal only). */
    public function discount(PromoCode $promo, int $subtotalCents): int
    {
        $value = StoreMoney::cents($promo->value);

        return $promo->type === PromoCodeType::Percent
            ? min($subtotalCents, StoreMoney::mulDiv($subtotalCents, $value, 10000))
            : min($subtotalCents, $value);
    }

    public function refuse(string $reason, array $replace = []): never
    {
        $message = __('store.promo.'.$reason, $replace);

        throw new ApiException(ErrorCode::PromoCodeInvalid, $message, ['promo_code' => [$message]]);
    }
}

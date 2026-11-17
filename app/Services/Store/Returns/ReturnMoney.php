<?php

namespace App\Services\Store\Returns;

use App\Services\Store\StoreMoney;

/**
 * What a return refunds (D345), in integer cents. The returned lines' paid share: their price at the order's unit
 * prices minus their share of the order's promo discount. Shipping is never refunded.
 *
 * The discount is prorated cumulatively over the order's live returns: after this return the order has given back
 * round_half_up(discount × returned gross / products subtotal) in total, so several partial returns never refund
 * more discount-free money than the order paid, and returning everything gives back exactly subtotal − discount.
 */
final class ReturnMoney
{
    /**
     * @return array{subtotal: int, discount: int, refund: int}
     */
    public static function compute(int $orderSubtotal, int $orderDiscount, int $previousGross, int $previousDiscount, int $gross): array
    {
        if ($orderSubtotal <= 0 || $orderDiscount <= 0) {
            return ['subtotal' => $gross, 'discount' => 0, 'refund' => $gross];
        }
        $cumulative = StoreMoney::mulDiv($orderDiscount, min($orderSubtotal, $previousGross + $gross), $orderSubtotal);
        $discount = max(0, min($gross, $cumulative - $previousDiscount));

        return ['subtotal' => $gross, 'discount' => $discount, 'refund' => $gross - $discount];
    }
}

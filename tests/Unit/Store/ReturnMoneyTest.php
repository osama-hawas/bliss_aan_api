<?php

namespace Tests\Unit\Store;

use App\Services\Store\Returns\ReturnMoney;
use PHPUnit\Framework\TestCase;

/**
 * S23 (D345): the paid share of returned lines, in cents; shipping is never part of it.
 */
class ReturnMoneyTest extends TestCase
{
    public function test_no_discount_refunds_the_lines(): void
    {
        $this->assertSame(['subtotal' => 4000, 'discount' => 0, 'refund' => 4000], ReturnMoney::compute(8000, 0, 0, 0, 4000));
    }

    public function test_the_discount_is_prorated_cumulatively(): void
    {
        // Subtotal 100.00 with a 10.00 discount, three returns of 33.33 / 33.33 / 33.34.
        $first = ReturnMoney::compute(10000, 1000, 0, 0, 3333);
        $second = ReturnMoney::compute(10000, 1000, 3333, $first['discount'], 3333);
        $third = ReturnMoney::compute(10000, 1000, 6666, $first['discount'] + $second['discount'], 3334);

        $this->assertSame(333, $first['discount']);
        $this->assertSame(334, $second['discount']); // round_half_up(1000 × 6666 / 10000) = 667 − 333
        $this->assertSame(1000, $first['discount'] + $second['discount'] + $third['discount']);
        $this->assertSame(9000, $first['refund'] + $second['refund'] + $third['refund']);
    }

    public function test_a_discount_never_exceeds_the_lines(): void
    {
        $this->assertSame(['subtotal' => 500, 'discount' => 500, 'refund' => 0], ReturnMoney::compute(1000, 1000, 0, 0, 500));
    }
}

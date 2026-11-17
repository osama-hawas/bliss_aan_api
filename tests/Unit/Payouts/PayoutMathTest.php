<?php

namespace Tests\Unit\Payouts;

use App\Services\Payouts\Cents;
use App\Support\SaudiIban;
use PHPUnit\Framework\TestCase;

/**
 * Slice 11 helpers: integer cents (D216, D219) and Saudi IBAN checks (contract §7).
 */
class PayoutMathTest extends TestCase
{
    public function test_cents_round_trip(): void
    {
        $this->assertSame(123450, Cents::fromDecimal('1234.5'));
        $this->assertSame(5, Cents::fromDecimal('0.05'));
        $this->assertSame(-1999, Cents::fromDecimal('-19.99'));
        $this->assertSame('1234.50', Cents::toDecimal(123450));
        $this->assertSame('-0.05', Cents::toDecimal(-5));
        $this->assertSame('0.00', Cents::toDecimal(0));
    }

    public function test_share_rounds_half_away_from_zero(): void
    {
        $this->assertSame(1251, Cents::share(10005, 1250)); // 12.50625
        $this->assertSame(1250, Cents::share(10000, 1250));
        $this->assertSame(1, Cents::share(5, 1000));        // 0.5 cent → 1
        $this->assertSame(0, Cents::share(4, 1000));
        $this->assertSame(-1251, Cents::share(-10005, 1250));
        $this->assertSame(1500, Cents::bps('15'));
        $this->assertSame(1250, Cents::bps('12.5'));
    }

    public function test_saudi_iban_validation(): void
    {
        $this->assertTrue(SaudiIban::isValid('SA0380000000608010167519'));
        $this->assertTrue(SaudiIban::isValid('sa03 8000 0000 6080 1016 7519'));
        $this->assertFalse(SaudiIban::isValid('SA0380000000608010167518')); // check digits
        $this->assertFalse(SaudiIban::isValid('SA038000000060801016751'));  // length
        $this->assertFalse(SaudiIban::isValid('GB82WEST12345698765432'));
        $this->assertSame('80', SaudiIban::bankCode('SA0380000000608010167519'));
        $this->assertSame('7519', SaudiIban::last4('SA0380000000608010167519'));
        $this->assertSame('SA•• •••• •••• •••• •••• 7519', SaudiIban::mask('7519'));
    }
}

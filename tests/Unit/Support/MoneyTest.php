<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_normalizes_to_two_decimals(): void
    {
        $this->assertSame('5500.00', Money::normalize(5500));
        $this->assertSame('5500.50', Money::normalize('5500.5'));
        $this->assertSame('0.05', Money::normalize('000.05'));
        $this->assertSame('-12.30', Money::normalize('-12.3'));
        $this->assertSame('0.00', Money::normalize('-0'));
    }

    public function test_rejects_floats_with_more_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('1.005');
    }
}

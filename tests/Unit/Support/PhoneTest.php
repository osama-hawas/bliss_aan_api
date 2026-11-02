<?php

namespace Tests\Unit\Support;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    #[DataProvider('valid')]
    public function test_normalizes_saudi_mobiles(string $input): void
    {
        $this->assertSame('+966512345678', Phone::normalizeSaudiMobile($input));
    }

    public static function valid(): array
    {
        return [['0512345678'], ['512345678'], ['966512345678'], ['+966 51 234 5678'], ['00966512345678'], ['٠٥١٢٣٤٥٦٧٨']];
    }

    #[DataProvider('invalid')]
    public function test_rejects_non_saudi_mobiles(?string $input): void
    {
        $this->assertNull(Phone::normalizeSaudiMobile($input));
    }

    public static function invalid(): array
    {
        return [[null], [''], ['0112345678'], ['05123'], ['+201012345678'], ['05123456789']];
    }
}

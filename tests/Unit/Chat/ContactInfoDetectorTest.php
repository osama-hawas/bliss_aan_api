<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ContactInfoDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D246 (S5): phone numbers, emails and URLs are detected; prices, dates, references and counts are not.
 * The Flutter `test/unit/contact_info_detector_test.dart` uses the same cases.
 */
class ContactInfoDetectorTest extends TestCase
{
    #[DataProvider('flagged')]
    public function test_flags_contact_details(string $body, string $kind): void
    {
        $kinds = (new ContactInfoDetector)->detect($body);

        $this->assertContains($kind, $kinds, $body);
        $this->assertTrue((new ContactInfoDetector)->flags($body));
    }

    #[DataProvider('clean')]
    public function test_leaves_ordinary_text_alone(?string $body): void
    {
        $this->assertSame([], (new ContactInfoDetector)->detect($body), (string) $body);
    }

    public static function flagged(): array
    {
        return [
            'saudi mobile' => ['0551234567', 'phone'],
            'spaced mobile' => ['جوالي 055 123 4567', 'phone'],
            'international saudi' => ['+966 55 123 4567', 'phone'],
            'dashed 00966' => ['00966-55-123-4567', 'phone'],
            'arabic-indic digits' => ['٠٥٥١٢٣٤٥٦٧', 'phone'],
            'mobile without zero' => ['551234567', 'phone'],
            'landline' => ['011 234 5678', 'phone'],
            'other country' => ['00971501234567', 'phone'],
            'plus international' => ['+44 20 7946 0958', 'phone'],
            'unified number' => ['920012345', 'phone'],
            'phone inside text' => ['the price is 1500 and my number 0551234567 ok', 'phone'],
            'email' => ['a@b.co', 'email'],
            'email in arabic text' => ['راسليني على noura.studio@gmail.com', 'email'],
            'https url' => ['https://x.y', 'url'],
            'www url' => ['www.site.sa', 'url'],
            'whatsapp link' => ['wa.me/966551234567', 'url'],
            'bare domain' => ['instagram.com/bliss', 'url'],
            'no-break spaces' => ["055\u{00A0}123\u{202F}4567", 'phone'],
            'dashed mobile' => ['055-123-4567', 'phone'],
        ];
    }

    public static function clean(): array
    {
        return [
            'price' => ['السعر 15000 ريال'],
            'big price' => ['Total 25,000.50 SAR'],
            'date and time' => ['2026-12-01 18:00'],
            'reference' => ['RN-1234567'],
            'guests' => ['100 ضيفة'],
            'decimal' => ['0.5'],
            'plain text' => ['Is the date available?'],
            'sentence end dot' => ['Thanks.See you'],
            'budget range' => ['الميزانية 5000-10000 ريال'],
            'budget range arabic digits' => ['الميزانية ٥٠٠٠-١٠٠٠٠ ريال'],
            'spaced range' => ['8000 - 12000'],
            'spaced range arabic digits' => ['٨٠٠٠ - ١٢٠٠٠'],
            'range from 9200' => ['9200-15000'],
            'range from 9200 arabic digits' => ['٩٢٠٠-١٥٠٠٠'],
            'date range' => ['2026-12-05 - 2026-12-08'],
            'date range arabic digits' => ['٢٠٢٦-١٢-٠٥ - ٢٠٢٦-١٢-٠٨'],
            'empty' => [''],
            'null' => [null],
        ];
    }
}

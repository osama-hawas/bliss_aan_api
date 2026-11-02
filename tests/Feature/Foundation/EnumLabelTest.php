<?php

namespace Tests\Feature\Foundation;

use App\Enums\ErrorCode;
use Tests\TestCase;

class EnumLabelTest extends TestCase
{
    /**
     * Every enum case and error code has an Arabic and English translation.
     */
    public function test_all_labels_are_translated(): void
    {
        $enums = collect(glob(app_path('Enums/*.php')))
            ->map(fn (string $file) => 'App\\Enums\\'.basename($file, '.php'))
            ->filter(fn (string $class) => method_exists($class, 'label'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($enums as $enum) {
                foreach ($enum::cases() as $case) {
                    $this->assertStringStartsNotWith('enums.', $case->label(), "{$enum}::{$case->name} missing in {$locale}");
                }
            }

            foreach (ErrorCode::cases() as $code) {
                $this->assertStringStartsNotWith('errors.', $code->message(), "{$code->value} missing in {$locale}");
            }
        }
    }
}

<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_defaults_to_arabic(): void
    {
        // The test client sends `en-us` unless told otherwise; an empty header = no preference.
        $this->getJson('/api/v1/nope', ['Accept-Language' => ''])
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'العنصر المطلوب غير موجود.');
    }

    public function test_primary_tag_is_parsed(): void
    {
        $this->getJson('/api/v1/nope', ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'The requested item was not found.');

        $this->getJson('/api/v1/nope', ['Accept-Language' => 'ar-SA'])->assertHeader('Content-Language', 'ar');
    }

    public function test_unsupported_language_falls_back_to_arabic(): void
    {
        $this->getJson('/api/v1/nope', ['Accept-Language' => 'fr-FR'])->assertHeader('Content-Language', 'ar');
    }

    public function test_falls_through_to_a_supported_preference(): void
    {
        $this->getJson('/api/v1/nope', ['Accept-Language' => 'fr-FR, en;q=0.8'])->assertHeader('Content-Language', 'en');
    }
}

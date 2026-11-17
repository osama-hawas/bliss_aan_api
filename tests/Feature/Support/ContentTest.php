<?php

namespace Tests\Feature\Support;

use App\Enums\Audience;
use App\Models\Faq;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * slice-12b-contract.md §4 (D158, D163): public FAQs and pages of one app.
 */
class ContentTest extends TestCase
{
    use RefreshDatabase;

    private function faq(Audience $app, string $en, int $sort, bool $active = true, ?string $ar = null): Faq
    {
        return Faq::query()->create([
            'audience' => $app,
            'question' => array_filter(['en' => $en, 'ar' => $ar ?? "س {$en}"]),
            'answer' => ['en' => "A {$en}", 'ar' => "ج {$en}"],
            'sort' => $sort,
            'is_active' => $active,
        ]);
    }

    public function test_faqs_of_one_app_in_order_for_guests(): void
    {
        $second = $this->faq(Audience::Client, 'Second', 2);
        $first = $this->faq(Audience::Client, 'First', 1);
        $this->faq(Audience::Client, 'Hidden', 0, false);
        $this->faq(Audience::Provider, 'Provider only', 0);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/faqs?app=client')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.question', 'First')
            ->assertJsonPath('data.0.answer', 'A First')
            ->assertJsonPath('data.1.id', $second->id);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/shared/faqs?app=provider')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'س Provider only');
    }

    public function test_missing_translation_falls_back_to_the_other_language(): void
    {
        Faq::query()->create(['audience' => Audience::Client, 'question' => ['ar' => 'سؤال فقط'], 'answer' => ['en' => 'Only English'], 'sort' => 1]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/faqs?app=client')
            ->assertJsonPath('data.0.question', 'سؤال فقط');
        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/shared/faqs?app=client')
            ->assertJsonPath('data.0.answer', 'Only English');
    }

    public function test_app_is_required(): void
    {
        $this->getJson('/api/v1/shared/faqs')->assertUnprocessable()->assertJsonValidationErrors(['app']);
        $this->getJson('/api/v1/shared/faqs?app=dashboard')->assertUnprocessable()->assertJsonValidationErrors(['app']);
        $this->getJson('/api/v1/shared/pages/terms')->assertUnprocessable()->assertJsonValidationErrors(['app']);
    }

    public function test_page_by_slug_and_app(): void
    {
        Page::query()->create(['slug' => 'terms', 'audience' => Audience::Client, 'title' => ['en' => 'Terms', 'ar' => 'الشروط'], 'body' => ['en' => "## One\nText", 'ar' => "## أولاً\nنص"]]);
        Page::query()->create(['slug' => 'terms', 'audience' => Audience::Provider, 'title' => ['en' => 'Agreement', 'ar' => 'الاتفاقية'], 'body' => ['en' => 'P', 'ar' => 'م']]);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/shared/pages/terms?app=client')->assertOk()
            ->assertJsonPath('data.slug', 'terms')
            ->assertJsonPath('data.title', 'الشروط')
            ->assertJsonPath('data.body', "## أولاً\nنص")
            ->assertJsonStructure(['data' => ['updated_at']]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/pages/terms?app=provider')
            ->assertJsonPath('data.title', 'Agreement');
    }

    public function test_unknown_or_inactive_page_is_404(): void
    {
        Page::query()->create(['slug' => 'privacy', 'audience' => Audience::Client, 'title' => ['en' => 'P'], 'body' => ['en' => 'B'], 'is_active' => false]);

        $this->getJson('/api/v1/shared/pages/privacy?app=client')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/shared/pages/nothing?app=client')->assertNotFound();
        $this->getJson('/api/v1/shared/pages/Bad_Slug?app=client')->assertNotFound();
    }

    public function test_a_valid_token_is_accepted_and_does_not_choose_the_app(): void
    {
        $this->faq(Audience::Client, 'Client one', 1);
        $provider = User::factory()->provider()->create();

        $this->actingAsApi($provider)->getJson('/api/v1/shared/faqs?app=client')->assertOk()->assertJsonCount(1, 'data');
        $this->withApiToken('garbage')->getJson('/api/v1/shared/faqs?app=client')->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature\Admin\Content;

use App\Enums\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsContent;
use Tests\TestCase;

/**
 * Slice 20 (D301–D303): admin editing of the pages and FAQs the apps read through Slice 12b's public endpoints.
 */
class PagesAndFaqsTest extends TestCase
{
    use BuildsContent, RefreshDatabase;

    private const PAGES = '/api/v1/admin/content-pages';

    private const FAQS = '/api/v1/admin/faqs';

    public function test_a_page_is_published_to_its_app_only_with_both_languages(): void
    {
        $admin = $this->admin(AdminRole::StoreContentOfficer);
        $body = ['slug' => 'terms', 'audience' => 'client', 'title' => ['ar' => 'الشروط'], 'body' => ['ar' => '## مقدمة']];

        $id = $this->actingAsApi($admin)->postJson(self::PAGES, $body)->assertCreated()
            ->assertJsonPath('data.status', 'hidden')->assertJsonPath('data.missing', ['en'])->json('data.id');
        $this->getJson('/api/v1/shared/pages/terms?app=client')->assertNotFound();
        $this->actingAsApi($admin)->postJson(self::PAGES."/{$id}/show")->assertStatus(409)->assertJsonPath('code', 'TRANSLATION_MISSING');

        $this->actingAsApi($admin)->putJson(self::PAGES."/{$id}", [...$body, 'title' => $this->both('Terms'), 'body' => $this->both('## Intro')])->assertOk();
        $this->actingAsApi($admin)->postJson(self::PAGES."/{$id}/show")->assertOk()->assertJsonPath('data.status', 'visible');
        $this->getJson('/api/v1/shared/pages/terms?app=client')->assertOk()->assertJsonPath('data.title', 'Terms ع');
        $this->getJson('/api/v1/shared/pages/terms?app=provider')->assertNotFound();

        // Slug and app are locked while visible; the same slug is free in the other app.
        $this->actingAsApi($admin)->putJson(self::PAGES."/{$id}", [...$body, 'slug' => 'terms-2', 'title' => $this->both('Terms'), 'body' => $this->both('x')])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->actingAsApi($admin)->postJson(self::PAGES, $body)->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->actingAsApi($admin)->postJson(self::PAGES, [...$body, 'audience' => 'provider'])->assertCreated();
        $this->actingAsApi($admin)->postJson(self::PAGES, [...$body, 'slug' => 'Bad Slug'])->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->actingAsApi($admin)->postJson(self::PAGES."/{$id}/hide", ['reason' => 'rewrite'])->assertOk();
        $this->getJson('/api/v1/shared/pages/terms?app=client')->assertNotFound();
        $this->actingAsApi($admin)->getJson(self::PAGES.'?filter[audience]=provider')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_faqs_are_ordered_by_sort_and_only_visible_ones_reach_the_app(): void
    {
        $admin = $this->admin();
        $first = $this->actingAsApi($admin)->postJson(self::FAQS, ['audience' => 'provider', 'question' => $this->both('Q1'), 'answer' => $this->both('A1')])
            ->assertCreated()->assertJsonPath('data.sort', 1)->json('data.id');
        $second = $this->actingAsApi($admin)->postJson(self::FAQS, ['audience' => 'provider', 'question' => $this->both('Q2'), 'answer' => $this->both('A2')])
            ->assertCreated()->assertJsonPath('data.sort', 2)->json('data.id');

        $this->getJson('/api/v1/shared/faqs?app=provider')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($admin)->postJson(self::FAQS."/{$first}/show")->assertOk();
        $this->actingAsApi($admin)->postJson(self::FAQS."/{$second}/show")->assertOk();
        $this->actingAsApi($admin)->putJson(self::FAQS."/{$second}", ['audience' => 'provider', 'question' => $this->both('Q2'), 'answer' => $this->both('A2'), 'sort' => 0])->assertOk();

        $this->getJson('/api/v1/shared/faqs?app=provider')->assertOk()->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.id', $first);
        $this->getJson('/api/v1/shared/faqs?app=client')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('faq_updated', $this->lastAuditRow()->action);
        $this->assertSame(['sort' => 2], $this->lastAuditRow()->old);
    }

    public function test_content_needs_content_manage(): void
    {
        $support = $this->admin(AdminRole::OperationsSupport);
        $this->actingAsApi($support)->getJson(self::PAGES)->assertForbidden();
        $this->actingAsApi($support)->postJson(self::FAQS, [])->assertForbidden();
    }
}

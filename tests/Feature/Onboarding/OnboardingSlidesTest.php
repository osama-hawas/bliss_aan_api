<?php

namespace Tests\Feature\Onboarding;

use App\Enums\AdminRole;
use App\Enums\MediaVisibility;
use App\Enums\UploadContext;
use App\Models\Media;
use App\Models\OnboardingSlide;
use App\Models\User;
use Database\Seeders\OnboardingSlideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsContent;
use Tests\TestCase;

/**
 * Slice 25 (D360–D368): onboarding slides managed from the dashboard, read by both apps through one public endpoint.
 */
class OnboardingSlidesTest extends TestCase
{
    use BuildsContent, RefreshDatabase;

    private const URL = '/api/v1/admin/onboarding-slides';

    private const PUBLIC = '/api/v1/shared/onboarding';

    private function onboardingUpload(User $admin, UploadContext $context = UploadContext::OnboardingImage): Media
    {
        return Media::query()->create([
            'collection' => $context->value,
            'disk' => MediaVisibility::Public->disk(),
            'path' => $context->value.'/'.uniqid().'.jpg',
            'mime' => 'image/jpeg',
            'size' => 100,
            'visibility' => MediaVisibility::Public,
            'uploaded_by' => $admin->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $app = 'client', string $title = 'Welcome'): array
    {
        return ['app' => $app, 'title' => $this->both($title), 'text' => $this->both("{$title} text")];
    }

    public function test_create_is_hidden_at_the_end_of_its_app_and_needs_an_own_unattached_onboarding_upload(): void
    {
        $admin = $this->admin(AdminRole::StoreContentOfficer);
        $other = $this->admin();

        $this->actingAsApi($admin)->postJson(self::URL, [...$this->body(), 'media_id' => $this->onboardingUpload($other)->id])
            ->assertUnprocessable()->assertJsonValidationErrors('media_id');
        $this->actingAsApi($admin)->postJson(self::URL, [...$this->body(), 'media_id' => $this->onboardingUpload($admin, UploadContext::Banner)->id])
            ->assertUnprocessable()->assertJsonValidationErrors('media_id');
        $this->actingAsApi($admin)->postJson(self::URL, ['app' => 'staff', 'title' => ['ar' => ''], 'text' => ['en' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['app', 'title.ar']);

        $media = $this->onboardingUpload($admin);
        $first = $this->actingAsApi($admin)->postJson(self::URL, [...$this->body(), 'text' => ['ar' => 'نص']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'hidden')
            ->assertJsonPath('data.app', 'client')
            ->assertJsonPath('data.sort', 1)
            ->assertJsonPath('data.missing', ['en'])
            ->assertJsonPath('data.image_url', null)
            ->json('data.id');
        $this->assertSame('onboarding_slide_created', $this->lastAuditRow()->action);

        $this->actingAsApi($admin)->postJson(self::URL, [...$this->body(), 'media_id' => $media->id])
            ->assertCreated()->assertJsonPath('data.sort', 2)->assertJsonPath('data.media_id', $media->id);
        $this->actingAsApi($admin)->postJson(self::URL, $this->body('provider'))->assertCreated()->assertJsonPath('data.sort', 1);

        // An attached upload cannot be reused.
        $this->actingAsApi($admin)->putJson(self::URL."/{$first}", [...$this->body(), 'media_id' => $media->id])
            ->assertUnprocessable()->assertJsonValidationErrors('media_id');

        $this->actingAsApi($admin)->getJson(self::URL.'?filter[app]=client')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[app]=provider')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[app]=staff')->assertUnprocessable();
    }

    public function test_show_needs_both_languages_hide_needs_a_reason_and_visible_text_keeps_both(): void
    {
        $admin = $this->admin();
        $id = $this->actingAsApi($admin)->postJson(self::URL, [...$this->body(), 'title' => ['ar' => 'أهلا']])->json('data.id');

        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertStatus(409)->assertJsonPath('code', 'TRANSLATION_MISSING');
        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", $this->body())->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertOk()->assertJsonPath('data.status', 'visible');
        $this->assertSame('onboarding_slide_shown', $this->lastAuditRow()->action);
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", [...$this->body(), 'text' => ['ar' => 'نص', 'en' => '']])
            ->assertUnprocessable()->assertJsonValidationErrors('text.en');

        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/hide")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/hide", ['reason' => 'new campaign'])
            ->assertOk()->assertJsonPath('data.status', 'hidden')->assertJsonPath('data.hidden_reason', 'new campaign');
        $audit = $this->lastAuditRow();
        $this->assertSame(['onboarding_slide_hidden', 'new campaign'], [$audit->action, $audit->reason]);

        // Hidden: a language may be removed; showing again clears the reason.
        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", [...$this->body(), 'text' => ['ar' => 'نص', 'en' => '']])->assertOk();
        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", $this->body())->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertOk()->assertJsonPath('data.hidden_reason', null);
        $this->assertDatabaseHas('onboarding_slides', ['id' => $id]); // nothing deleted
        $this->actingAsApi($admin)->deleteJson(self::URL."/{$id}")->assertStatus(405);
    }

    public function test_the_image_is_replaced_or_removed_and_the_old_file_is_detached_not_deleted(): void
    {
        $admin = $this->admin();
        $old = $this->onboardingUpload($admin);
        $id = $this->actingAsApi($admin)->postJson(self::URL, [...$this->body(), 'media_id' => $old->id])->json('data.id');

        $new = $this->onboardingUpload($admin);
        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", [...$this->body(), 'media_id' => $new->id])
            ->assertOk()->assertJsonPath('data.media_id', $new->id);
        $this->assertNull($old->fresh()->attachable_id);
        $this->assertSame(['media_id' => $new->id], $this->lastAuditRow()->new);

        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", [...$this->body(), 'remove_image' => true])
            ->assertOk()->assertJsonPath('data.media_id', null)->assertJsonPath('data.image_url', null);
        $this->assertNotNull($new->fresh());
    }

    public function test_move_reorders_inside_one_app_only(): void
    {
        $admin = $this->admin();
        $ids = [];
        foreach (['A', 'B', 'C'] as $title) {
            $ids[$title] = $this->actingAsApi($admin)->postJson(self::URL, $this->body('client', $title))->json('data.id');
        }
        $provider = $this->actingAsApi($admin)->postJson(self::URL, $this->body('provider', 'P'))->json('data.id');

        $this->actingAsApi($admin)->postJson(self::URL."/{$ids['C']}/move", ['position' => 0])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::URL."/{$ids['C']}/move", ['position' => 1])->assertOk()->assertJsonPath('data.sort', 1);
        $this->assertSame(['onboarding_slide_moved', ['position' => 3], ['position' => 1]], [$this->lastAuditRow()->action, $this->lastAuditRow()->old, $this->lastAuditRow()->new]);

        $order = $this->actingAsApi($admin)->getJson(self::URL.'?filter[app]=client')->json('data.*.id');
        $this->assertSame([$ids['C'], $ids['A'], $ids['B']], $order);

        // Past the end = last.
        $this->actingAsApi($admin)->postJson(self::URL."/{$ids['C']}/move", ['position' => 99])->assertOk()->assertJsonPath('data.sort', 3);
        $this->assertSame([$ids['A'], $ids['B'], $ids['C']], $this->actingAsApi($admin)->getJson(self::URL.'?filter[app]=client')->json('data.*.id'));
        $this->assertSame(1, OnboardingSlide::query()->find($provider)->sort);
    }

    public function test_public_endpoint_returns_visible_slides_of_the_asked_app_in_order_with_locale_fallback(): void
    {
        $admin = $this->admin();
        $media = $this->onboardingUpload($admin);
        $a = $this->actingAsApi($admin)->postJson(self::URL, [...$this->body('client', 'One'), 'media_id' => $media->id])->json('data.id');
        $b = $this->actingAsApi($admin)->postJson(self::URL, $this->body('client', 'Two'))->json('data.id');
        $hidden = $this->actingAsApi($admin)->postJson(self::URL, $this->body('client', 'Draft'))->json('data.id');
        $p = $this->actingAsApi($admin)->postJson(self::URL, $this->body('provider', 'Join'))->json('data.id');
        foreach ([$a, $b, $p] as $id) {
            $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertOk();
        }
        $this->actingAsApi($admin)->postJson(self::URL."/{$b}/move", ['position' => 1])->assertOk();

        $this->getJson(self::PUBLIC.'?app=client')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $b)
            ->assertJsonPath('data.0.title', 'Two')
            ->assertJsonPath('data.0.image_url', null)
            ->assertJsonPath('data.1.id', $a)
            ->assertJsonPath('data.1.text', 'One text');
        $this->assertNotNull($this->getJson(self::PUBLIC.'?app=client')->json('data.1.image_url'));
        $this->getJson(self::PUBLIC.'?app=provider')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $p);
        $this->assertNotContains($hidden, $this->getJson(self::PUBLIC.'?app=client')->json('data.*.id'));

        $this->getJson(self::PUBLIC.'?app=client', ['Accept-Language' => 'ar'])->assertOk()->assertJsonPath('data.0.title', 'Two ع');

        // A slide hidden after a language was emptied is not served; an ar-only text falls back for en (seeded rows).
        OnboardingSlide::query()->whereKey($a)->update(['title' => json_encode(['ar' => 'واحد', 'en' => ''])]);
        $this->getJson(self::PUBLIC.'?app=client')->assertJsonPath('data.1.title', 'واحد');

        // A signed-in user may call it too; the app comes from `app`, not the token.
        $this->actingAsApi($admin)->getJson(self::PUBLIC.'?app=provider')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_public_endpoint_validates_app(): void
    {
        $this->getJson(self::PUBLIC)->assertUnprocessable()->assertJsonValidationErrors('app');
        $this->getJson(self::PUBLIC.'?app=admin')->assertUnprocessable()->assertJsonValidationErrors('app');
        $this->getJson(self::PUBLIC.'?app=client')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_seeder_gives_both_apps_three_visible_slides_and_never_touches_existing_ones(): void
    {
        $this->seed(OnboardingSlideSeeder::class);
        $this->getJson(self::PUBLIC.'?app=client')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'Your occasions, your details');
        $this->getJson(self::PUBLIC.'?app=provider', ['Accept-Language' => 'ar'])->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.title', 'احصلي على أرباحك');

        OnboardingSlide::query()->where('app', 'client')->update(['is_active' => false]);
        $this->seed(OnboardingSlideSeeder::class);
        $this->assertSame(6, OnboardingSlide::query()->count());
        $this->getJson(self::PUBLIC.'?app=client')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_slides_need_content_manage(): void
    {
        $support = $this->admin(AdminRole::OperationsSupport);
        $this->actingAsApi($support)->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($support)->postJson(self::URL, $this->body())->assertForbidden();
    }
}

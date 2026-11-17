<?php

namespace Tests\Feature\Admin\Content;

use App\Enums\AdminRole;
use App\Models\Banner;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsContent;
use Tests\TestCase;

/**
 * Slice 20 (D301, D304): client-app banners. Created hidden, shown with an image and both languages, hidden with a
 * reason; `shared/home` shows only the visible, running ones.
 */
class BannersTest extends TestCase
{
    use BuildsCatalog, BuildsContent, RefreshDatabase;

    private const URL = '/api/v1/admin/banners';

    public function test_create_is_hidden_and_needs_an_own_unattached_banner_upload(): void
    {
        $admin = $this->admin(AdminRole::StoreContentOfficer);
        $other = $this->admin();
        $category = $this->category();

        $this->actingAsApi($admin)->postJson(self::URL, ['title' => ['ar' => 'عرض', 'en' => ''], 'media_id' => $this->bannerUpload($other)->id])
            ->assertUnprocessable()->assertJsonValidationErrors('media_id');
        $this->actingAsApi($admin)->postJson(self::URL, ['title' => ['ar' => '', 'en' => ''], 'media_id' => $this->bannerUpload($admin)->id])
            ->assertUnprocessable()->assertJsonValidationErrors('title.ar');
        $this->actingAsApi($admin)->postJson(self::URL, ['title' => ['ar' => 'عرض'], 'media_id' => $this->bannerUpload($admin)->id, 'destination' => ['type' => 'category', 'id' => 999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('destination.id');

        $media = $this->bannerUpload($admin);
        $this->actingAsApi($admin)->postJson(self::URL, [
            'title' => ['ar' => 'عرض', 'en' => ''], 'media_id' => $media->id, 'destination' => ['type' => 'category', 'id' => $category->id],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'hidden')
            ->assertJsonPath('data.missing', ['en'])
            ->assertJsonPath('data.destination', ['type' => 'category', 'id' => $category->id])
            ->assertJsonPath('data.media_id', $media->id);
        $this->assertSame('banner_created', $this->lastAuditRow()->action);
    }

    public function test_show_needs_both_languages_and_a_running_window_then_reaches_home(): void
    {
        $admin = $this->admin();
        $media = $this->bannerUpload($admin);
        $id = $this->actingAsApi($admin)->postJson(self::URL, ['title' => ['ar' => 'عرض'], 'media_id' => $media->id])->json('data.id');

        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertStatus(409)->assertJsonPath('code', 'TRANSLATION_MISSING');
        $this->getJson('/api/v1/shared/home')->assertOk()->assertJsonCount(0, 'data.banners');

        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", ['title' => ['ar' => 'عرض', 'en' => 'Offer'], 'ends_at' => now()->subDay()->toIso8601String(), 'starts_at' => now()->subDays(3)->toIso8601String()])->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", ['title' => ['ar' => 'عرض', 'en' => 'Offer']])->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertOk()->assertJsonPath('data.status', 'visible')->assertJsonPath('data.window', 'running');
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/show")->assertStatus(409);
        $this->getJson('/api/v1/shared/home')->assertOk()->assertJsonCount(1, 'data.banners')->assertJsonPath('data.banners.0.id', $id);

        // A visible banner keeps both languages.
        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", ['title' => ['ar' => 'عرض', 'en' => '']])->assertUnprocessable()->assertJsonValidationErrors('title.en');

        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/hide")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->postJson(self::URL."/{$id}/hide", ['reason' => 'season over'])->assertOk()->assertJsonPath('data.status', 'hidden');
        $this->assertSame('season over', $this->lastAuditRow()->reason);
        $this->getJson('/api/v1/shared/home')->assertOk()->assertJsonCount(0, 'data.banners');
        $this->assertNotNull(Banner::query()->find($id)); // nothing deleted
    }

    public function test_replacing_the_image_detaches_the_old_file(): void
    {
        $admin = $this->admin();
        $first = $this->bannerUpload($admin);
        $id = $this->actingAsApi($admin)->postJson(self::URL, ['title' => $this->both('Offer'), 'media_id' => $first->id])->json('data.id');
        $second = $this->bannerUpload($admin);

        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", ['title' => $this->both('Offer'), 'media_id' => $second->id])
            ->assertOk()->assertJsonPath('data.media_id', $second->id);
        $this->assertNull(Media::query()->find($first->id)->attachable_id);
        $this->assertNotNull(Media::query()->find($first->id)); // detached, not deleted
    }

    public function test_list_filters_and_permissions(): void
    {
        $admin = $this->admin();
        $riyadh = $this->city();
        $this->actingAsApi($admin)->postJson(self::URL, ['title' => $this->both('One'), 'media_id' => $this->bannerUpload($admin)->id, 'city_id' => $riyadh->id])->assertCreated();
        $this->actingAsApi($admin)->postJson(self::URL, ['title' => $this->both('Two'), 'media_id' => $this->bannerUpload($admin)->id])->assertCreated();

        $this->actingAsApi($admin)->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['meta' => ['total']]);
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[city_id]='.$riyadh->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?q=Two')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=visible')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=title')->assertUnprocessable();

        $this->actingAsApi($this->admin(AdminRole::OperationsSupport))->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL)->assertUnauthorized();
    }
}

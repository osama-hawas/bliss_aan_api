<?php

namespace Tests\Feature\Provider;

use App\Enums\ProviderStatus;
use App\Enums\UploadContext;
use App\Models\Addon;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceInclusion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Req 32, 33: an active provider picks catalog services and prices them (custom or the guide price),
 * switches catalog inclusions off, prices catalog add-ons, and sets her hours, photo, bio and portfolio.
 */
class SetupTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/provider';

    private Provider $provider;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->application(ProviderStatus::Active);
        $this->service = Service::query()->sole();
        $this->service->update(['guide_price' => '1500.00']);
    }

    private function as(): static
    {
        return $this->actingAsApi($this->provider->user);
    }

    public function test_only_an_active_provider_sets_up(): void
    {
        $draft = $this->applicant();
        $this->actingAsApi($draft)->getJson(self::URL.'/services')->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi(User::factory()->client()->create())->getJson(self::URL.'/services')->assertForbidden();
    }

    public function test_the_catalog_shows_guide_prices_inclusions_and_addons(): void
    {
        ServiceInclusion::query()->create(['service_id' => $this->service->id, 'text' => ['ar' => 'ساعتان', 'en' => 'Two hours'], 'sort' => 1]);
        $addon = Addon::query()->create(['name' => ['ar' => 'ألبوم', 'en' => 'Album'], 'is_active' => true]);
        $addon->services()->attach($this->service);

        $this->as()->withHeader('Accept-Language', 'en')->getJson(self::URL.'/catalog')
            ->assertOk()
            ->assertJsonPath('data.0.guide_price', ['amount' => '1500.00', 'currency' => 'SAR'])
            ->assertJsonPath('data.0.inclusions.0.text', 'Two hours')
            ->assertJsonPath('data.0.addons.0.name', 'Album');
    }

    public function test_she_sets_and_resets_her_price(): void
    {
        $id = $this->provider->services()->sole()->id;

        $this->as()->patchJson(self::URL."/services/{$id}", ['custom_price' => 1800, 'at_provider_location' => true])
            ->assertOk()
            ->assertJsonPath('data.price.amount', '1800.00')
            ->assertJsonPath('data.uses_guide_price', false)
            ->assertJsonPath('data.at_provider_location', true);
        $this->as()->patchJson(self::URL."/services/{$id}", ['custom_price' => null])
            ->assertOk()
            ->assertJsonPath('data.price.amount', '1500.00')
            ->assertJsonPath('data.uses_guide_price', true);
        $this->as()->patchJson(self::URL."/services/{$id}", ['custom_price' => -5])->assertUnprocessable()->assertJsonValidationErrors(['custom_price']);
        $this->as()->patchJson(self::URL."/services/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_she_adds_a_catalog_service(): void
    {
        $other = Service::query()->create(['category_id' => $this->service->category_id, 'name' => ['ar' => 'فيديو', 'en' => 'Video'], 'is_active' => true, 'sort' => 2]);

        $this->as()->postJson(self::URL.'/services', ['service_id' => $other->id, 'at_provider_location' => true])->assertCreated()->assertJsonPath('data.uses_guide_price', true);
        $this->as()->getJson(self::URL.'/services')->assertJsonCount(2, 'data');
        $other->update(['is_active' => false]);
        $this->as()->postJson(self::URL.'/services', ['service_id' => $other->id, 'at_provider_location' => true])->assertUnprocessable();
    }

    public function test_inclusions_switch_off_only_from_the_catalog(): void
    {
        $inclusion = ServiceInclusion::query()->create(['service_id' => $this->service->id, 'text' => ['ar' => 'ساعتان', 'en' => 'Two hours'], 'sort' => 1]);
        $id = $this->provider->services()->sole()->id;

        $this->as()->putJson(self::URL."/services/{$id}/inclusions", ['disabled_inclusion_ids' => [$inclusion->id]])
            ->assertOk()->assertJsonPath('data.inclusions.0.enabled', false);
        $this->as()->putJson(self::URL."/services/{$id}/inclusions", ['disabled_inclusion_ids' => []])
            ->assertOk()->assertJsonPath('data.inclusions.0.enabled', true);
        $this->as()->putJson(self::URL."/services/{$id}/inclusions", ['disabled_inclusion_ids' => [99999]])->assertUnprocessable();
    }

    public function test_she_prices_a_catalog_addon_for_the_services_it_belongs_to(): void
    {
        $addon = Addon::query()->create(['name' => ['ar' => 'ألبوم', 'en' => 'Album'], 'is_active' => true]);
        $addon->services()->attach($this->service);
        $offered = $this->provider->services()->sole();

        $this->as()->putJson(self::URL."/addons/{$addon->id}", ['price' => 300, 'is_active' => true, 'provider_service_ids' => [$offered->id]])
            ->assertOk()
            ->assertJsonPath('data.price.amount', '300.00')
            ->assertJsonPath('data.provider_service_ids', [$offered->id]);
        $this->as()->getJson(self::URL.'/services')->assertJsonPath('data.0.addons.0.price.amount', '300.00');

        $unrelated = Addon::query()->create(['name' => ['ar' => 'ورد', 'en' => 'Flowers'], 'is_active' => true]);
        $this->as()->putJson(self::URL."/addons/{$unrelated->id}", ['price' => 100, 'is_active' => true, 'provider_service_ids' => [$offered->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['provider_service_ids']);
        $this->as()->putJson(self::URL.'/addons/99999', ['price' => 100, 'is_active' => true, 'provider_service_ids' => []])->assertNotFound();
    }

    public function test_working_hours_replace_the_week(): void
    {
        $this->as()->putJson(self::URL.'/working-hours', ['hours' => [
            ['weekday' => 0, 'opens_at' => '10:00', 'closes_at' => '18:00'],
            ['weekday' => 4, 'opens_at' => '16:00', 'closes_at' => '23:30'],
        ]])->assertOk()->assertJsonCount(2, 'data.working_hours')->assertJsonPath('data.working_hours.1.closes_at', '23:30');

        $this->as()->putJson(self::URL.'/working-hours', ['hours' => [['weekday' => 1, 'opens_at' => '18:00', 'closes_at' => '10:00']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['hours.0.closes_at']);
        $this->as()->putJson(self::URL.'/working-hours', ['hours' => [
            ['weekday' => 2, 'opens_at' => '10:00', 'closes_at' => '12:00'], ['weekday' => 2, 'opens_at' => '13:00', 'closes_at' => '15:00'],
        ]])->assertUnprocessable();
        $this->as()->putJson(self::URL.'/working-hours', ['hours' => []])->assertOk()->assertJsonCount(0, 'data.working_hours');
    }

    public function test_profile_bio_avatar_and_portfolio(): void
    {
        $user = $this->provider->user;

        $this->as()->patchJson(self::URL.'/profile', ['bio' => 'New bio', 'accepts_open_requests' => false])
            ->assertOk()->assertJsonPath('data.bio', 'New bio')->assertJsonPath('data.accepts_open_requests', false);

        $this->as()->putJson(self::URL.'/profile/avatar', ['media_id' => $this->uploaded($user, UploadContext::Portfolio)->id])->assertUnprocessable();
        $url = $this->as()->putJson(self::URL.'/profile/avatar', ['media_id' => $this->uploaded($user, UploadContext::Avatar)->id])
            ->assertOk()->json('data.avatar_url');
        $this->assertNotNull($url);
        $this->as()->putJson(self::URL.'/profile/avatar', ['media_id' => null])->assertOk()->assertJsonPath('data.avatar_url', null);

        $photo = $this->as()->postJson(self::URL.'/portfolio', ['media_id' => $this->uploaded($user, UploadContext::Portfolio)->id])
            ->assertCreated()->json('data.portfolio.0.id');
        $this->as()->deleteJson(self::URL."/portfolio/{$photo}")->assertOk()->assertJsonCount(0, 'data.portfolio');
    }

    public function test_she_never_reaches_another_providers_services(): void
    {
        $other = $this->application(ProviderStatus::Active);
        $theirs = $other->services()->sole()->id;

        $this->as()->patchJson(self::URL."/services/{$theirs}", ['custom_price' => 1])->assertNotFound();
        $this->as()->putJson(self::URL."/services/{$theirs}/inclusions", ['disabled_inclusion_ids' => []])->assertNotFound();
        $this->assertNull($other->services()->sole()->custom_price);
    }
}

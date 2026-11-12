<?php

namespace Tests\Feature\Provider;

use App\Enums\ProviderStatus;
use App\Models\City;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * D74: every offered service has at least one execution place — the client's event location (confirmed per D73)
 * and/or the provider's own location. A service with neither is hidden from clients; the last place cannot be
 * switched off; nothing is switched on for her.
 */
class ExecutionPlacesTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/provider';

    private Provider $provider;

    private Service $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->application(ProviderStatus::Active);
        $this->other = Service::query()->create(['category_id' => Service::query()->sole()->category_id, 'name' => ['ar' => 'فيديو', 'en' => 'Video'], 'is_active' => true, 'sort' => 2]);
    }

    private function as(string $lang = 'en'): static
    {
        return $this->actingAsApi($this->provider->user)->withHeader('Accept-Language', $lang);
    }

    private function add(array $places): TestResponse
    {
        return $this->as()->postJson(self::URL.'/services', ['service_id' => $this->other->id, ...$places]);
    }

    private function added(): ProviderService
    {
        return $this->provider->services()->where('service_id', $this->other->id)->sole();
    }

    private function seenByClients(ProviderService $offered): bool
    {
        return $this->getJson("/api/v1/shared/providers/{$this->provider->id}/services/{$offered->id}")->isOk();
    }

    public function test_a_new_service_needs_a_place(): void
    {
        $this->add([])->assertUnprocessable()->assertJsonValidationErrors('execution_places');
        $this->add(['at_event_location' => false, 'at_provider_location' => false])->assertUnprocessable()->assertJsonValidationErrors('execution_places');
        $this->assertSame(0, $this->provider->services()->where('service_id', $this->other->id)->count());
    }

    public function test_both_places_with_the_event_location_confirmed(): void
    {
        $this->add(['at_event_location' => true, 'at_provider_location' => true])->assertUnprocessable()->assertJsonValidationErrors('confirm');

        $this->add(['at_event_location' => true, 'at_provider_location' => true, 'confirm' => true])
            ->assertCreated()
            ->assertJsonPath('data.at_event_location', true)
            ->assertJsonPath('data.at_provider_location', true)
            ->assertJsonPath('data.execution_place_missing', false)
            ->assertJsonPath('data.event_location_needs_review', false);
        $this->assertNotNull($this->added()->event_location_confirmed_at);
        $this->assertTrue($this->seenByClients($this->added()));
    }

    public function test_event_location_only_needs_the_confirmation(): void
    {
        $this->add(['at_event_location' => true])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->add(['at_event_location' => true, 'confirm' => true])->assertCreated();

        $offered = $this->added();
        $this->assertTrue($offered->at_event_location);
        $this->assertFalse($offered->at_provider_location);
        $this->assertNotNull($offered->event_location_confirmed_at);
        $this->assertTrue(ProviderService::query()->openRequestCapable()->whereKey($offered->id)->exists());
    }

    public function test_provider_location_only_needs_no_confirmation(): void
    {
        $this->add(['at_provider_location' => true])->assertCreated()->assertJsonPath('data.at_event_location', false);

        $offered = $this->added();
        $this->assertNull($offered->event_location_confirmed_at);
        // Direct requests can reach it; open requests cannot (D55, D65).
        $this->assertTrue($this->seenByClients($offered));
        $this->assertFalse(ProviderService::query()->openRequestCapable()->whereKey($offered->id)->exists());
    }

    public function test_one_place_can_go_while_the_other_stays(): void
    {
        $this->add(['at_event_location' => true, 'at_provider_location' => true, 'confirm' => true])->assertCreated();
        $id = $this->added()->id;

        $this->as()->patchJson(self::URL."/services/{$id}", ['at_provider_location' => false])->assertOk()->assertJsonPath('data.at_provider_location', false);
        $this->as()->patchJson(self::URL."/services/{$id}", ['at_provider_location' => true])->assertOk();
        $this->as()->putJson(self::URL."/services/{$id}/event-location", ['at_event_location' => false])->assertOk()->assertJsonPath('data.at_event_location', false);
        $this->assertTrue($this->seenByClients($this->added()));
    }

    public function test_the_last_place_cannot_be_switched_off(): void
    {
        $this->add(['at_provider_location' => true])->assertCreated();
        $id = $this->added()->id;
        $this->as('ar')->patchJson(self::URL."/services/{$id}", ['at_provider_location' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('at_provider_location')
            ->assertJsonPath('errors.at_provider_location.0', 'يجب أن يبقى مكان واحد على الأقل لتقديم الخدمة مفعّلًا. فعّلي المكان الآخر أولًا.');
        $this->assertTrue($this->added()->at_provider_location);

        // 0 and "0" pass the boolean rule too: still the last place, still refused.
        foreach ([0, '0'] as $off) {
            $this->as()->patchJson(self::URL."/services/{$id}", ['at_provider_location' => $off])
                ->assertUnprocessable()->assertJsonValidationErrors('at_provider_location');
            $this->assertTrue($this->added()->at_provider_location);
        }
        $this->as()->patchJson(self::URL."/services/{$id}", ['is_active' => '0'])->assertOk();
        $this->assertFalse($this->added()->is_active);
        $this->as()->patchJson(self::URL."/services/{$id}", ['is_active' => 1])->assertOk();

        $legacy = $this->provider->services()->where('service_id', '!=', $this->other->id)->sole();
        $legacy->forceFill(['at_event_location' => true, 'at_provider_location' => false])->save();
        $this->as()->putJson(self::URL."/services/{$legacy->id}/event-location", ['at_event_location' => false])
            ->assertUnprocessable()
            ->assertJsonPath('errors.at_event_location.0', 'At least one place to deliver this service must stay on. Turn on the other place first.');
        $this->assertTrue($legacy->refresh()->at_event_location);
    }

    public function test_a_service_without_a_place_is_hidden_and_never_switched_on_for_her(): void
    {
        // Picked on the application: no place yet (nothing is switched on automatically).
        $offered = $this->provider->services()->sole();
        $this->assertFalse($offered->at_event_location);
        $this->assertFalse($offered->at_provider_location);

        $this->as()->getJson(self::URL.'/services')->assertJsonPath('data.0.execution_place_missing', true);
        $this->assertFalse($this->seenByClients($offered));

        // Other edits still work and leave the places alone; "switching off" a place that is already off is a no-op.
        $this->as()->patchJson(self::URL."/services/{$offered->id}", ['custom_price' => 900])->assertOk();
        $this->as()->patchJson(self::URL."/services/{$offered->id}", ['at_provider_location' => false])->assertOk();
        $this->as()->putJson(self::URL."/services/{$offered->id}/event-location", ['at_event_location' => false])->assertOk();
        $this->assertFalse($offered->refresh()->at_provider_location);
        $this->assertFalse($offered->at_event_location);

        // It cannot be offered again without one.
        $this->as()->patchJson(self::URL."/services/{$offered->id}", ['is_active' => false])->assertOk();
        $this->as()->patchJson(self::URL."/services/{$offered->id}", ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->as()->postJson(self::URL.'/services', ['service_id' => $offered->service_id])->assertUnprocessable()->assertJsonValidationErrors('execution_places');

        // Choosing one makes it visible.
        $this->as()->postJson(self::URL.'/services', ['service_id' => $offered->service_id, 'at_provider_location' => true])
            ->assertCreated()->assertJsonPath('data.execution_place_missing', false);
        $this->assertTrue($this->seenByClients($offered->refresh()));
    }

    public function test_clients_never_see_a_service_without_a_place(): void
    {
        $city = City::query()->sole();
        $placeless = $this->provider->services()->sole();
        $client = User::factory()->client()->create();

        // Her only service has no place, so she is not shown at all (D75).
        $this->getJson("/api/v1/shared/providers/{$this->provider->id}")->assertNotFound();
        $this->getJson('/api/v1/shared/providers?filter[city_id]='.$city->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/shared/providers?q=Event')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsApi($client)->putJson("/api/v1/client/favorites/provider_service/{$placeless->id}")->assertNotFound();

        // With a place she appears everywhere, with a starting price.
        $this->as()->patchJson(self::URL."/services/{$placeless->id}", ['at_provider_location' => true])->assertOk();
        $this->getJson("/api/v1/shared/providers/{$this->provider->id}")->assertOk()->assertJsonCount(1, 'data.services');
        $this->getJson('/api/v1/shared/providers?filter[city_id]='.$city->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/shared/providers?q=Event')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsApi($client)->putJson("/api/v1/client/favorites/provider_service/{$placeless->id}")->assertSuccessful();
    }

    public function test_re_offering_keeps_her_places(): void
    {
        $this->add(['at_provider_location' => true])->assertCreated();
        $id = $this->added()->id;
        $this->as()->patchJson(self::URL."/services/{$id}", ['is_active' => false])->assertOk();

        $this->add([])->assertCreated()->assertJsonPath('data.at_provider_location', true)->assertJsonPath('data.is_active', true);
    }

    public function test_the_general_update_cannot_set_the_event_location(): void
    {
        $id = $this->provider->services()->sole()->id;
        $this->as()->patchJson(self::URL."/services/{$id}", ['at_event_location' => true, 'at_provider_location' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('at_event_location');
        $this->assertFalse(ProviderService::query()->find($id)->at_provider_location);
    }

    public function test_she_never_reaches_another_providers_service(): void
    {
        $user = $this->applicant();
        $theirs = $this->application(ProviderStatus::Active, $user)->services()->sole();

        $this->as()->patchJson(self::URL."/services/{$theirs->id}", ['at_provider_location' => true])->assertNotFound();
        $this->assertFalse($theirs->refresh()->at_provider_location);
        $this->actingAsApi(User::factory()->client()->create())->postJson(self::URL.'/services', ['service_id' => $this->other->id, 'at_provider_location' => true])->assertForbidden();
    }
}

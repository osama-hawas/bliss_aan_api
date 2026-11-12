<?php

namespace Tests\Feature\Provider;

use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Models\Provider;
use App\Models\ProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * D73 matching boundary. `ProviderService::openRequestCapable()` is the only event-location check open-request
 * matching may use (Slice 4 adds the rest of the matching on top of it); direct requests and discovery keep
 * reading the stored `at_event_location`.
 */
class OpenRequestCapabilityTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private Provider $provider;

    private ProviderService $offered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->application(ProviderStatus::Active);
        $this->offered = $this->provider->services()->sole();
    }

    private function capable(): bool
    {
        return ProviderService::query()->visible()->openRequestCapable()->whereKey($this->offered->id)->exists();
    }

    private function answer(bool $enabled): void
    {
        $body = $enabled ? ['at_event_location' => true, 'confirm' => true] : ['at_event_location' => false];
        $this->actingAsApi($this->provider->user)
            ->putJson("/api/v1/provider/services/{$this->offered->id}/event-location", $body)
            ->assertOk();
    }

    public function test_a_new_service_does_not_qualify(): void
    {
        $this->assertFalse($this->capable());
    }

    public function test_a_true_value_from_before_d73_does_not_qualify(): void
    {
        $this->offered->forceFill(['at_event_location' => true, 'event_location_confirmed_at' => null])->save();

        $this->assertFalse($this->capable());
    }

    public function test_confirming_qualifies_disabling_removes_and_reconfirming_restores(): void
    {
        // Her own location stays on, so the event location can be switched off (D74).
        $this->offered->update(['at_provider_location' => true]);
        $this->answer(true);
        $this->assertTrue($this->capable());

        $this->answer(false);
        $this->assertFalse($this->capable());

        $this->answer(true);
        $this->assertTrue($this->capable());
    }

    public function test_a_confirmation_does_not_bypass_the_other_rules(): void
    {
        $this->answer(true);

        $this->offered->update(['is_active' => false]);
        $this->assertFalse($this->capable());
        $this->offered->update(['is_active' => true]);

        $this->provider->update(['status' => ProviderStatus::Suspended]);
        $this->assertFalse($this->capable());
        $this->provider->update(['status' => ProviderStatus::Active]);

        $this->provider->user->update(['status' => UserStatus::Suspended]);
        $this->assertFalse($this->capable());
        $this->provider->user->update(['status' => UserStatus::Active]);

        $this->offered->service->update(['is_active' => false]);
        $this->assertFalse($this->capable());
        $this->offered->service->update(['is_active' => true]);

        $this->assertTrue($this->capable());
    }

    public function test_direct_requests_and_discovery_still_read_the_stored_value(): void
    {
        // Unconfirmed, from before D73: excluded from open requests, unchanged for everything else.
        $this->offered->forceFill(['at_event_location' => true, 'event_location_confirmed_at' => null])->save();

        $this->assertFalse($this->capable());
        $this->assertTrue(ProviderService::query()->visible()->whereKey($this->offered->id)->exists());
        $this->getJson("/api/v1/shared/providers/{$this->provider->id}/services/{$this->offered->id}")
            ->assertOk()
            ->assertJsonPath('data.at_event_location', true);
    }
}

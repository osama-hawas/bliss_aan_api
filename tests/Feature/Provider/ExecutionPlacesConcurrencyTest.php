<?php

namespace Tests\Feature\Provider;

use App\Enums\ProviderStatus;
use App\Models\ProviderService;
use App\Services\Providers\ProviderSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * D74 under interleaving: a request holds a copy of the service read before another request committed a place
 * change. The last-place rule must be checked against the committed row, not that stale copy.
 * (Real two-session locking is in Tests\Feature\Database\ExecutionPlaceLockingTest.)
 */
class ExecutionPlacesConcurrencyTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private ProviderService $offered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->offered = $this->application(ProviderStatus::Active)->services()->sole();
        $this->offered->forceFill(['at_event_location' => true, 'event_location_confirmed_at' => now(), 'at_provider_location' => true])->save();
    }

    private function providerSetup(): ProviderSetup
    {
        return app(ProviderSetup::class);
    }

    public function test_switching_off_her_location_after_the_event_location_went_elsewhere_is_refused(): void
    {
        $stale = ProviderService::query()->find($this->offered->id);
        // The other request commits first: the event location is off now.
        DB::table('provider_services')->where('id', $this->offered->id)->update(['at_event_location' => false, 'event_location_confirmed_at' => null]);

        try {
            $this->providerSetup()->updateService($stale, ['at_provider_location' => false]);
            $this->fail('The last place was switched off.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('at_provider_location', $e->errors());
        }

        $row = ProviderService::query()->find($this->offered->id);
        $this->assertTrue($row->at_provider_location);
        $this->assertTrue($row->hasExecutionPlace());
    }

    public function test_switching_off_the_event_location_after_her_location_went_elsewhere_is_refused(): void
    {
        $stale = ProviderService::query()->find($this->offered->id);
        DB::table('provider_services')->where('id', $this->offered->id)->update(['at_provider_location' => false]);

        try {
            $this->providerSetup()->setEventLocation($stale, false);
            $this->fail('The last place was switched off.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('at_event_location', $e->errors());
        }

        $row = ProviderService::query()->find($this->offered->id);
        $this->assertTrue($row->at_event_location);
        $this->assertNotNull($row->event_location_confirmed_at);
    }

    public function test_the_stale_copy_never_overwrites_a_newer_place(): void
    {
        $stale = ProviderService::query()->find($this->offered->id);
        // Meanwhile her location was switched off; a price change from the stale copy must not switch it back on.
        DB::table('provider_services')->where('id', $this->offered->id)->update(['at_provider_location' => false]);

        $saved = $this->providerSetup()->updateService($stale, ['custom_price' => '900.00']);

        $this->assertFalse($saved->at_provider_location);
        $this->assertSame('900.00', ProviderService::query()->find($this->offered->id)->custom_price);
        $this->assertFalse(ProviderService::query()->find($this->offered->id)->at_provider_location);
    }

    public function test_re_offering_uses_the_committed_places(): void
    {
        // Stopped, with only her location on.
        DB::table('provider_services')->where('id', $this->offered->id)->update(['is_active' => false, 'at_event_location' => false, 'event_location_confirmed_at' => null]);
        $provider = $this->offered->provider;
        // Meanwhile that place was switched off too: re-offering without sending a place must be refused.
        DB::table('provider_services')->where('id', $this->offered->id)->update(['at_provider_location' => false]);

        try {
            $this->providerSetup()->addService($provider, $this->offered->service_id);
            $this->fail('A service without a place was offered.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('execution_places', $e->errors());
        }
        $this->assertFalse(ProviderService::query()->find($this->offered->id)->is_active);
    }
}

<?php

namespace Tests\Feature\Provider;

use App\Enums\ProviderStatus;
use App\Models\ActivityEvent;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * D73: offering a service at the client's event location is off by default and counts only once the provider
 * confirms it herself. Values set before D73 stay as they are but need her review.
 */
class EventLocationTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/provider';

    private Provider $provider;

    private ProviderService $offered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->application(ProviderStatus::Active);
        $this->offered = $this->provider->services()->sole();
    }

    private function as(): static
    {
        return $this->actingAsApi($this->provider->user);
    }

    private function url(?ProviderService $offered = null): string
    {
        return self::URL.'/services/'.($offered ?? $this->offered)->id.'/event-location';
    }

    /**
     * A row as it was before D73: on, never confirmed.
     */
    private function legacy(): ProviderService
    {
        $this->offered->forceFill(['at_event_location' => true, 'event_location_confirmed_at' => null])->save();

        return $this->offered;
    }

    public function test_new_provider_services_start_off_and_unconfirmed(): void
    {
        // Column default (the application helper sets neither flag).
        $this->assertFalse($this->offered->refresh()->at_event_location);
        $this->assertNull($this->offered->event_location_confirmed_at);

        // Added in setup.
        $other = Service::query()->create(['category_id' => Service::query()->sole()->category_id, 'name' => ['ar' => 'فيديو', 'en' => 'Video'], 'is_active' => true]);
        $this->as()->postJson(self::URL.'/services', ['service_id' => $other->id, 'at_provider_location' => true])
            ->assertCreated()
            ->assertJsonPath('data.at_event_location', false)
            ->assertJsonPath('data.event_location_confirmed_at', null)
            ->assertJsonPath('data.event_location_needs_review', false);

        // Picked on the application.
        $applicant = $this->applicant();
        $this->actingAsApi($applicant)->putJson(self::URL.'/application', ['service_ids' => [$other->id]])->assertOk();
        $this->assertDatabaseHas('provider_services', ['provider_id' => $applicant->provider->id, 'service_id' => $other->id, 'at_event_location' => false, 'event_location_confirmed_at' => null]);
    }

    public function test_switching_it_on_needs_her_explicit_confirmation(): void
    {
        $this->as()->putJson($this->url(), ['at_event_location' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->as()->putJson($this->url(), ['at_event_location' => true, 'confirm' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertFalse($this->offered->refresh()->at_event_location);

        $this->as()->putJson($this->url(), ['at_event_location' => true, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.at_event_location', true)
            ->assertJsonPath('data.event_location_needs_review', false);

        $this->offered->refresh();
        $this->assertTrue($this->offered->at_event_location);
        $this->assertNotNull($this->offered->event_location_confirmed_at);
        $this->assertTrue(ActivityEvent::query()->whereMorphedTo('subject', $this->provider)->where('event', 'event_location_confirmed')->exists());
    }

    public function test_switching_it_off_removes_the_confirmation(): void
    {
        $this->offered->update(['at_provider_location' => true]);
        $this->as()->putJson($this->url(), ['at_event_location' => true, 'confirm' => true])->assertOk();

        $this->as()->putJson($this->url(), ['at_event_location' => false])
            ->assertOk()
            ->assertJsonPath('data.at_event_location', false)
            ->assertJsonPath('data.event_location_confirmed_at', null);

        $this->assertDatabaseHas('provider_services', ['id' => $this->offered->id, 'at_event_location' => false, 'event_location_confirmed_at' => null]);
        $this->assertTrue(ActivityEvent::query()->whereMorphedTo('subject', $this->provider)->where('event', 'event_location_disabled')->exists());
    }

    public function test_a_value_from_before_d73_is_not_a_confirmation_until_she_reviews_it(): void
    {
        $this->legacy();

        $this->as()->getJson(self::URL.'/services')
            ->assertOk()
            ->assertJsonPath('data.0.at_event_location', true)
            ->assertJsonPath('data.0.event_location_confirmed_at', null)
            ->assertJsonPath('data.0.event_location_needs_review', true);

        $this->as()->putJson($this->url(), ['at_event_location' => true, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.event_location_needs_review', false);
        $this->assertNotNull($this->offered->refresh()->event_location_confirmed_at);
    }

    public function test_she_can_also_answer_a_review_by_switching_it_off(): void
    {
        $this->legacy();

        // Her only place: refused (D74), nothing changes.
        $this->as()->putJson($this->url(), ['at_event_location' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('at_event_location');
        $this->assertTrue($this->offered->refresh()->at_event_location);

        // With her own location on, the event location can go.
        $this->as()->patchJson(self::URL.'/services/'.$this->offered->id, ['at_provider_location' => true])->assertOk();
        $this->as()->putJson($this->url(), ['at_event_location' => false])
            ->assertOk()
            ->assertJsonPath('data.event_location_needs_review', false);
        $this->assertFalse($this->offered->refresh()->at_event_location);
    }

    public function test_the_general_service_update_cannot_set_it(): void
    {
        $this->as()->patchJson(self::URL.'/services/'.$this->offered->id, ['at_event_location' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('at_event_location');
        $this->assertFalse($this->offered->refresh()->at_event_location);

        // The other place still saves through it.
        $this->as()->patchJson(self::URL.'/services/'.$this->offered->id, ['at_provider_location' => true])
            ->assertOk()->assertJsonPath('data.at_provider_location', true);
    }

    public function test_validation_failure(): void
    {
        $this->as()->putJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors('at_event_location');
        $this->as()->putJson($this->url(), ['at_event_location' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('at_event_location');
        // A string is not a boolean, even "true".
        $this->as()->putJson($this->url(), ['at_event_location' => 'true', 'confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('at_event_location');
        $this->as()->putJson($this->url(), ['at_event_location' => true, 'confirm' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertNull($this->offered->refresh()->event_location_confirmed_at);
    }

    public function test_the_confirmation_message_reads_naturally(): void
    {
        $ar = $this->as()->withHeader('Accept-Language', 'ar')->putJson($this->url(), ['at_event_location' => true])->json('errors.confirm.0');
        $en = $this->as()->withHeader('Accept-Language', 'en')->putJson($this->url(), ['at_event_location' => true])->json('errors.confirm.0');

        $this->assertStringNotContainsString('true', $ar);
        $this->assertStringNotContainsString('true', $en);
        $this->assertStringContainsString('موقع مناسبة العميلة', $ar);
    }

    public function test_stopping_and_re_offering_a_service_keeps_her_answer(): void
    {
        $this->as()->putJson($this->url(), ['at_event_location' => true, 'confirm' => true])->assertOk();
        $confirmedAt = $this->offered->refresh()->event_location_confirmed_at;

        $this->as()->patchJson(self::URL.'/services/'.$this->offered->id, ['is_active' => false])->assertOk();
        $this->as()->postJson(self::URL.'/services', ['service_id' => $this->offered->service_id])
            ->assertCreated()
            ->assertJsonPath('data.at_event_location', true)
            ->assertJsonPath('data.event_location_needs_review', false);

        $this->assertTrue($this->offered->refresh()->at_event_location);
        $this->assertEquals($confirmedAt, $this->offered->event_location_confirmed_at);
    }

    public function test_she_never_reaches_another_providers_service(): void
    {
        $other = $this->application(ProviderStatus::Active, $this->applicantNamed('Huda'))->services()->sole();

        $this->as()->putJson($this->url($other), ['at_event_location' => true, 'confirm' => true])
            ->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertNull($other->refresh()->event_location_confirmed_at);
    }

    public function test_only_an_active_provider_and_only_providers(): void
    {
        $this->provider->update(['status' => ProviderStatus::Suspended]);
        $this->as()->putJson($this->url(), ['at_event_location' => true, 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'INVALID_STATE');

        $this->actingAsApi(User::factory()->client()->create())
            ->putJson($this->url(), ['at_event_location' => true, 'confirm' => true])
            ->assertForbidden();
    }

    private function applicantNamed(string $name): User
    {
        $user = $this->applicant();
        $user->update(['name' => $name]);

        return $user;
    }
}

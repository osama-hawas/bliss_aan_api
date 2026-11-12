<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\GuestCountMode;
use App\Enums\ServiceRequestType;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequests\ReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Creating direct and open requests (§6.3, §7, §10, §11, §15).
 */
class CreateRequestTest extends TestCase
{
    use BuildsServiceRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
    }

    // ── Direct, event location ──────────────────────────────────────────────────────────────

    public function test_direct_event_location_request(): void
    {
        $provider = $this->matchingProvider();
        $addon = $this->catalogAddon();
        $this->priceAddon($provider, $addon);

        $response = $this->submit($this->directBody($provider, ['addon_ids' => [$addon->id], 'guests_count' => 80, 'notes' => 'Bride entrance at 8']))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.status', 'awaiting_offers')
            ->assertJsonPath('data.status_label', 'Awaiting offers')
            ->assertJsonPath('data.provider.id', $provider->id)
            ->assertJsonPath('data.service.id', $this->photo->id)
            ->assertJsonPath('data.event_type', ['id' => $this->wedding->id, 'name' => 'Wedding'])
            ->assertJsonPath('data.execution_place', 'event_location')
            ->assertJsonPath('data.start_time', '19:00')
            ->assertJsonPath('data.duration_minutes', 180)
            ->assertJsonPath('data.event_passed', false)
            ->assertJsonPath('data.city.id', $this->riyadh->id)
            ->assertJsonPath('data.address.details', 'Villa 12, King Fahd Rd')
            ->assertJsonPath('data.address.lat', 24.7136)
            ->assertJsonPath('data.guests_count', 80)
            ->assertJsonPath('data.addons', [['id' => $addon->id, 'name' => 'Album']])
            ->assertJsonPath('data.notes', 'Bride entrance at 8')
            ->assertJsonPath('data.history.0.event', 'request_created')
            ->assertJsonPath('data.history.0.to_status', 'awaiting_offers');

        $this->assertMatchesRegularExpression('/^RN-\d{7}$/', $response->json('data.reference'));
        $request = ServiceRequest::query()->sole();
        $this->assertSame([$provider->id], $request->recipients()->pluck('provider_id')->all());
        $this->assertSame('new', $request->recipients()->sole()->status->value);
        $this->assertNotNull($request->addons()->value('provider_addon_id'));
    }

    public function test_direct_request_does_not_need_the_d73_confirmation(): void
    {
        // An existing `true` from before D73, never confirmed: direct requests read the stored value (D73.5).
        $provider = $this->matchingProvider(offer: ['event_location_confirmed_at' => null]);

        $this->submit($this->directBody($provider))->assertCreated()->assertJsonPath('data.type', 'direct');
    }

    public function test_direct_request_refusals(): void
    {
        $provider = $this->matchingProvider();

        // Not visible: suspended owner.
        $hidden = $this->matchingProvider('Hidden');
        $hidden->user->update(['status' => 'suspended']);
        $this->submit($this->directBody($hidden))->assertUnprocessable()->assertJsonValidationErrors('provider_id', 'errors');

        // Service not offered by her.
        $other = $this->service($this->photo->category, 'Henna');
        $this->submit($this->directBody($provider, ['service_id' => $other->id]))->assertJsonValidationErrors('service_id', 'errors');

        // She does not offer the event location.
        $atHome = $this->matchingProvider('At home', offer: ['at_event_location' => false, 'at_provider_location' => true]);
        $this->submit($this->directBody($atHome))->assertJsonValidationErrors('execution_place', 'errors');

        // Add-on she does not offer with this service.
        $addon = $this->catalogAddon();
        $this->submit($this->directBody($provider, ['addon_ids' => [$addon->id]]))->assertJsonValidationErrors('addon_ids', 'errors');

        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_direct_request_outside_coverage(): void
    {
        $provider = $this->matchingProvider();
        $jeddah = $this->city('Jeddah', 'جدة');

        $this->submit($this->directBody($provider, ['address' => [...$this->openBody()['address'], 'city_id' => $jeddah->id]]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'OUT_OF_COVERAGE');
    }

    public function test_a_direct_request_is_never_turned_into_an_open_one(): void
    {
        $target = $this->matchingProvider('Target');
        $this->matchingProvider('Other');

        $this->submit($this->directBody($target))->assertCreated();

        $request = ServiceRequest::query()->sole();
        $this->assertSame(ServiceRequestType::Direct, $request->type);
        $this->assertSame([$target->id], $request->recipients()->pluck('provider_id')->all());
    }

    // ── Direct, provider location ───────────────────────────────────────────────────────────

    public function test_direct_provider_location_uses_her_city_without_address_or_coverage(): void
    {
        $jeddah = $this->city('Jeddah', 'جدة');
        // She covers only Riyadh but works from Jeddah: no coverage check for her own location (D66).
        $provider = $this->matchingProvider(offer: ['at_event_location' => false, 'at_provider_location' => true], attributes: ['city_id' => $jeddah->id]);

        $this->submit($this->directBody($provider, ['execution_place' => 'provider_location', 'address' => null]))
            ->assertCreated()
            ->assertJsonPath('data.execution_place', 'provider_location')
            ->assertJsonPath('data.city.id', $jeddah->id)
            ->assertJsonPath('data.address', null);

        $this->assertNull(ServiceRequest::query()->sole()->address_id);
    }

    public function test_provider_location_refusals(): void
    {
        $provider = $this->matchingProvider(offer: ['at_provider_location' => true]);

        // An address is not accepted.
        $this->submit($this->directBody($provider, ['execution_place' => 'provider_location']))
            ->assertJsonValidationErrors('address', 'errors');

        // She does not offer her own location.
        $eventOnly = $this->matchingProvider('Event only');
        $this->submit($this->directBody($eventOnly, ['execution_place' => 'provider_location', 'address' => null]))
            ->assertJsonValidationErrors('execution_place', 'errors');

        // She has no city.
        $noCity = $this->matchingProvider('No city', offer: ['at_provider_location' => true], attributes: ['city_id' => null]);
        $this->submit($this->directBody($noCity, ['execution_place' => 'provider_location', 'address' => null]))
            ->assertJsonValidationErrors('execution_place', 'errors');
    }

    // ── Open ────────────────────────────────────────────────────────────────────────────────

    public function test_open_request_goes_to_exactly_the_matching_providers(): void
    {
        $addon = $this->catalogAddon();
        $match = $this->matchingProvider('Match');
        $this->priceAddon($match, $addon);
        $second = $this->matchingProvider('Second match');
        $this->priceAddon($second, $addon);

        $noAddon = $this->matchingProvider('No add-on');
        $inactiveAddon = $this->matchingProvider('Inactive add-on');
        $this->priceAddon($inactiveAddon, $addon, active: false);
        $closed = $this->matchingProvider('No open requests', attributes: ['accepts_open_requests' => false]);
        $this->priceAddon($closed, $addon);
        $elsewhere = $this->matchingProvider('Jeddah only', cities: [$this->city('Jeddah', 'جدة')]);
        $this->priceAddon($elsewhere, $addon);
        $unconfirmed = $this->matchingProvider('Unconfirmed', offer: ['event_location_confirmed_at' => null]);
        $this->priceAddon($unconfirmed, $addon);
        $atHome = $this->matchingProvider('Own location only', offer: ['at_event_location' => false, 'at_provider_location' => true]);
        $this->priceAddon($atHome, $addon);
        $suspended = $this->matchingProvider('Suspended');
        $this->priceAddon($suspended, $addon);
        $suspended->user->update(['status' => 'suspended']);

        $this->submit($this->openBody(['addon_ids' => [$addon->id]]))
            ->assertCreated()
            ->assertJsonPath('data.type', 'open')
            ->assertJsonPath('data.provider', null);

        $this->assertEqualsCanonicalizing([$match->id, $second->id], ServiceRequest::query()->sole()->recipients()->pluck('provider_id')->all());
        $this->assertNotContains($noAddon->id, ServiceRequest::query()->sole()->recipients()->pluck('provider_id')->all());
    }

    public function test_unconfirmed_record_is_excluded_from_open_but_still_takes_a_direct_request(): void
    {
        $unconfirmed = $this->matchingProvider(offer: ['event_location_confirmed_at' => null]);

        $this->submit($this->openBody())->assertUnprocessable()->assertJsonPath('code', 'NO_MATCHING_PROVIDERS');
        $this->submit($this->directBody($unconfirmed))->assertCreated();
    }

    public function test_open_request_with_no_match_creates_nothing(): void
    {
        $this->allowAttachments();
        $file = $this->attachmentUpload();

        $this->submit($this->openBody(['media_ids' => [$file->id]]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_MATCHING_PROVIDERS');

        $this->assertSame(0, ServiceRequest::query()->count());
        $this->assertNull($file->fresh()->attachable_id);
    }

    public function test_open_request_only_for_the_event_location(): void
    {
        $this->matchingProvider(offer: ['at_provider_location' => true]);

        $this->submit($this->openBody(['execution_place' => 'provider_location', 'address' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('execution_place', 'errors');
    }

    public function test_open_request_refuses_a_provider_id(): void
    {
        $provider = $this->matchingProvider();

        $this->submit($this->openBody(['provider_id' => $provider->id]))->assertJsonValidationErrors('provider_id', 'errors');
    }

    public function test_eligibility_hint_matches_creation_and_creation_rechecks(): void
    {
        $url = '/api/v1/client/open-request-eligibility?service_id='.$this->photo->id.'&city_id='.$this->riyadh->id;
        $this->actingAsApi($this->client)->getJson($url)
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => null, 'meta' => [], 'data' => ['service_id' => $this->photo->id, 'city_id' => $this->riyadh->id, 'open_request_available' => false]]);

        $provider = $this->matchingProvider();
        $this->actingAsApi($this->client)->getJson($url)->assertJsonPath('data.open_request_available', true);

        $addon = $this->catalogAddon();
        $this->actingAsApi($this->client)->getJson($url.'&addon_ids[]='.$addon->id)->assertJsonPath('data.open_request_available', false);

        // The market changes after a `true` hint: creation still refuses.
        $provider->update(['accepts_open_requests' => false]);
        $this->submit($this->openBody())->assertJsonPath('code', 'NO_MATCHING_PROVIDERS');
    }

    // ── Address ─────────────────────────────────────────────────────────────────────────────

    public function test_event_location_address_rules(): void
    {
        $this->matchingProvider();
        $noPin = $this->client->addresses()->create(['city_id' => $this->riyadh->id, 'details' => 'No pin']);
        $foreign = User::factory()->client()->create()->addresses()->create(['city_id' => $this->riyadh->id, 'details' => 'x', 'lat' => 1, 'lng' => 1]);

        $this->submit($this->openBody(['address' => null]))->assertJsonValidationErrors('address_id', 'errors');
        $this->submit($this->openBody(['address_id' => $noPin->id]))->assertJsonValidationErrors('address', 'errors');
        $this->submit($this->openBody(['address' => null, 'address_id' => $noPin->id]))->assertJsonValidationErrors('address_id', 'errors');
        $this->submit($this->openBody(['address' => null, 'address_id' => $foreign->id]))->assertJsonValidationErrors('address_id', 'errors');
        $this->submit($this->openBody(['address' => ['city_id' => $this->riyadh->id, 'details' => 'x']]))->assertJsonValidationErrors(['address.lat', 'address.lng'], 'errors');

        $this->assertSame(0, ServiceRequest::query()->count());
    }

    // ── Guest count (D54) ───────────────────────────────────────────────────────────────────

    public function test_guest_count_follows_the_service_mode(): void
    {
        $this->matchingProvider();

        $this->photo->update(['guest_count_mode' => GuestCountMode::Hidden]);
        $this->submit($this->openBody(['guests_count' => 10]))->assertJsonValidationErrors('guests_count', 'errors');
        $this->submit($this->openBody())->assertCreated()->assertJsonPath('data.guests_count', null);

        $this->photo->update(['guest_count_mode' => GuestCountMode::Required]);
        $this->submit($this->openBody())->assertJsonValidationErrors('guests_count', 'errors');
        $this->submit($this->openBody(['guests_count' => 0]))->assertJsonValidationErrors('guests_count', 'errors');
        $this->submit($this->openBody(['guests_count' => 50]))->assertCreated()->assertJsonPath('data.guests_count', 50);

        $this->photo->update(['guest_count_mode' => GuestCountMode::Optional]);
        $this->submit($this->openBody())->assertCreated()->assertJsonPath('data.guests_count', null);
        $this->submit($this->openBody(['guests_count' => 5]))->assertCreated()->assertJsonPath('data.guests_count', 5);
    }

    public function test_services_expose_the_guest_count_mode(): void
    {
        $provider = $this->matchingProvider();
        $offered = $provider->services()->sole();

        $this->getJson('/api/v1/shared/services')->assertJsonPath('data.0.guest_count_mode', 'optional');
        $this->getJson("/api/v1/shared/providers/{$provider->id}/services/{$offered->id}")->assertJsonPath('data.guest_count_mode', 'optional');
    }

    // ── Time (D48, D50, D61, D62) ───────────────────────────────────────────────────────────

    public function test_riyadh_time_is_stored_in_utc_and_may_cross_midnight(): void
    {
        $this->matchingProvider();

        $this->submit($this->openBody(['event_date' => '2030-12-31', 'start_time' => '23:30', 'duration_minutes' => 120]))
            ->assertCreated()
            ->assertJsonPath('data.event_date', '2030-12-31')
            ->assertJsonPath('data.start_time', '23:30')
            ->assertJsonPath('data.starts_at', '2030-12-31T20:30:00Z')
            ->assertJsonPath('data.ends_at', '2030-12-31T22:30:00Z');

        $this->submit($this->openBody(['event_date' => '2031-01-31', 'start_time' => '02:00', 'duration_minutes' => 1440]))
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2031-01-30T23:00:00Z')
            ->assertJsonPath('data.ends_at', '2031-01-31T23:00:00Z');
    }

    public function test_the_start_must_be_in_the_future(): void
    {
        $this->matchingProvider();

        $this->submit($this->openBody(['event_date' => now('Asia/Riyadh')->subDay()->format('Y-m-d')]))
            ->assertJsonValidationErrors('start_time', 'errors');
        $this->submit($this->openBody(['duration_minutes' => 0, 'start_time' => '7pm']))
            ->assertJsonValidationErrors(['duration_minutes', 'start_time'], 'errors');
    }

    public function test_event_passed_flips_exactly_at_the_end_and_blocks_nothing(): void
    {
        $this->matchingProvider();
        $request = $this->created($this->openBody(['event_date' => '2030-06-01', 'start_time' => '18:00', 'duration_minutes' => 60]));
        $show = fn () => $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}");

        $this->travelTo('2030-06-01 15:30:00'); // between start (15:00 UTC) and end (16:00 UTC)
        $show()->assertJsonPath('data.event_passed', false);
        $this->travelTo('2030-06-01 15:59:59');
        $show()->assertJsonPath('data.event_passed', false);
        $this->travelTo('2030-06-01 16:00:00');
        $show()->assertJsonPath('data.event_passed', true);

        $this->actingAsApi($this->client)->postJson("/api/v1/client/service-requests/{$request->id}/cancel")->assertOk();
    }

    // ── Idempotency and reference (§15, D72) ────────────────────────────────────────────────

    public function test_idempotent_replay_and_conflict(): void
    {
        $this->matchingProvider();
        $key = (string) Str::uuid();
        $body = $this->openBody(['addon_ids' => []]);

        $first = $this->submit($body, $key)->assertCreated()->json('data.id');
        $this->submit([...$body, 'start_time' => '19:00'], $key)->assertOk()->assertJsonPath('data.id', $first);
        $this->submit([...$body, 'duration_minutes' => 60], $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');

        // Another client may use the same key.
        $this->submit($body, $key, User::factory()->client()->create())->assertCreated();
        $this->assertSame(2, ServiceRequest::query()->count());
    }

    public function test_the_idempotency_key_is_required(): void
    {
        $this->matchingProvider();

        $this->actingAsApi($this->client)->postJson('/api/v1/client/service-requests', $this->openBody())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key', 'errors');
    }

    public function test_a_reference_collision_is_retried(): void
    {
        $this->matchingProvider();
        // Bound before the first request: the route keeps its controller (and its creator) between test requests.
        $this->app->instance(ReferenceGenerator::class, new class extends ReferenceGenerator
        {
            private int $calls = 0;

            public function next(): string
            {
                // 1st request: RN-0000001. 2nd: collides twice, then gets RN-0000042.
                return ++$this->calls <= 3 ? 'RN-0000001' : 'RN-0000042';
            }
        });

        $this->submit($this->openBody())->assertCreated()->assertJsonPath('data.reference', 'RN-0000001');
        $this->submit($this->openBody())->assertCreated()->assertJsonPath('data.reference', 'RN-0000042');
    }

    public function test_reference_retries_are_bounded(): void
    {
        $this->matchingProvider();
        $this->app->instance(ReferenceGenerator::class, new class extends ReferenceGenerator
        {
            public function next(): string
            {
                return 'RN-0000001';
            }
        });

        $this->submit($this->openBody())->assertCreated();
        $this->submit($this->openBody())->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR')->assertJsonMissingPath('debug');
        $this->assertSame(1, ServiceRequest::query()->count());
    }

    public function test_wrong_user_type_and_validation(): void
    {
        $this->submit($this->openBody(), as: User::factory()->provider()->create())->assertForbidden();
        $this->submit([])->assertUnprocessable()->assertJsonValidationErrors(['type', 'service_id', 'event_type_id', 'execution_place', 'event_date', 'start_time', 'duration_minutes'], 'errors');
    }
}

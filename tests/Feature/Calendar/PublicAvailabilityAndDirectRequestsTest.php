<?php

namespace Tests\Feature\Calendar;

use App\Enums\ProviderStatus;
use App\Models\ActivityEvent;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAvailability;
use Tests\TestCase;

/**
 * Slice 5a §7 public availability (D86, D92, D93), §8 Requirement 17 on direct requests (D70) and §9 open requests
 * (D84). Riyadh = UTC + 3 h.
 */
class PublicAvailabilityAndDirectRequestsTest extends TestCase
{
    use BuildsAvailability, RefreshDatabase;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens', ['at_provider_location' => true]);
    }

    private function availability(?Provider $provider = null, string $from = '2026-12-01', string $to = '2026-12-31')
    {
        return $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/shared/providers/'.($provider ?? $this->noura)->id."/availability?from={$from}&to={$to}");
    }

    // ── Public availability ─────────────────────────────────────────────────────────────────

    public function test_guest_and_client_get_the_same_dates_only(): void
    {
        $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z')->update(['reason' => 'Private']);
        $this->storeBlock($this->noura, '2026-12-06T10:00:00Z', '2026-12-06T14:00:00Z');

        $guest = $this->availability()->assertOk()
            ->assertExactJson(['success' => true, 'message' => null, 'meta' => [], 'data' => [
                'provider_id' => $this->noura->id, 'from' => '2026-12-01', 'to' => '2026-12-31', 'unavailable_dates' => ['2026-12-05'],
            ]]);
        $this->assertStringNotContainsString('Private', $guest->getContent());

        $this->actingAsApi($this->client)->getJson("/api/v1/shared/providers/{$this->noura->id}/availability?from=2026-12-01&to=2026-12-31")
            ->assertOk()->assertJsonPath('data', $guest->json('data'));

        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken('not-a-token')
            ->getJson("/api/v1/shared/providers/{$this->noura->id}/availability?from=2026-12-01&to=2026-12-31")->assertUnauthorized();
    }

    public function test_coverage_table(): void
    {
        // §7.1 rows mapped onto separate dates of December (UTC = Riyadh − 3 h).
        $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z'); // 05: whole day → yes
        $this->storeBlock($this->noura, '2026-12-06T10:00:00Z', '2026-12-06T14:00:00Z'); // 06: 13–17 → no
        $this->storeBlock($this->noura, '2026-12-06T21:00:00Z', '2026-12-07T09:00:00Z'); // 07: 00–12 + 12–24 → yes
        $this->storeBlock($this->noura, '2026-12-07T09:00:00Z', '2026-12-07T21:00:00Z');
        $this->storeBlock($this->noura, '2026-12-07T21:00:00Z', '2026-12-08T09:00:00Z'); // 08: one-minute gap → no
        $this->storeBlock($this->noura, '2026-12-08T09:01:00Z', '2026-12-08T21:00:00Z');
        $this->storeBlock($this->noura, '2026-12-09T21:00:00Z', '2026-12-10T20:59:00Z'); // 10: 00:00–23:59 → no
        $this->storeBlock($this->noura, '2026-12-11T15:00:00Z', '2026-12-13T03:00:00Z'); // 11 18:00 → 13 06:00: 12 yes
        $this->storeBlock($this->noura, '2026-12-14T17:00:00Z', '2026-12-15T21:00:00Z'); // 14 20:00 → 15 24:00: 15 yes
        $this->storeBlock($this->noura, '2026-12-16T21:00:00Z', '2026-12-17T21:00:00Z')->delete(); // 17 deleted → no

        $this->availability()->assertOk()->assertJsonPath('data.unavailable_dates', ['2026-12-05', '2026-12-07', '2026-12-12', '2026-12-15']);
        $this->availability(from: '2026-12-12', to: '2026-12-12')->assertJsonPath('data.unavailable_dates', ['2026-12-12']);
        $this->availability(from: '2026-12-20', to: '2026-12-31')->assertJsonPath('data.unavailable_dates', []);
    }

    public function test_hidden_providers_are_404(): void
    {
        $hidden = $this->matchingProvider('Hidden', attributes: ['status' => ProviderStatus::Suspended]);
        $this->storeBlock($hidden, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $this->availability($hidden)->assertNotFound();
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/shared/providers/999999/availability?from=2026-12-01&to=2026-12-31')->assertNotFound();
        $noService = $this->provider('No service', [$this->riyadh]);
        $this->availability($noService)->assertNotFound();
    }

    public function test_range_validation(): void
    {
        $this->availability(from: '2026-12-01', to: '2027-01-31')->assertOk();
        $this->availability(from: '2026-12-01', to: '2027-02-01')->assertJsonValidationErrors(['to'], 'errors');
        $this->availability(from: '2026-11-30', to: '2026-12-05')->assertJsonValidationErrors(['from'], 'errors');
        $this->availability(from: '2026-12-05', to: '2026-12-04')->assertJsonValidationErrors(['to'], 'errors');
        $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/shared/providers/{$this->noura->id}/availability")->assertJsonValidationErrors(['from', 'to'], 'errors');
    }

    // ── Requirement 17 ──────────────────────────────────────────────────────────────────────

    public function test_direct_request_inside_a_block_is_refused_and_creates_nothing(): void
    {
        $this->allowAttachments();
        $file = $this->attachmentUpload();
        $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');

        foreach (['event_location', 'provider_location'] as $place) {
            $body = $this->directAt($this->noura, '2026-12-05', '19:00', 60, ['execution_place' => $place, 'media_ids' => [$file->id]]);
            if ($place === 'provider_location') {
                unset($body['address']);
            }
            $this->submit($body)->assertStatus(409)->assertExactJson([
                'success' => false,
                'message' => 'This provider is not available at the selected time. Please choose another date or time.',
                'code' => 'SLOT_UNAVAILABLE',
                'errors' => [],
            ]);
        }

        $this->assertSame(0, ServiceRequest::query()->count());
        $this->assertSame(0, ServiceRequestRecipient::query()->count());
        $this->assertSame(0, ActivityEvent::query()->where('event', 'request_created')->count());
        $this->assertNull(Media::query()->find($file->id)->attachable_id);
    }

    public function test_partial_day_block_is_exact(): void
    {
        $this->storeBlock($this->noura, '2026-12-06T10:00:00Z', '2026-12-06T14:00:00Z'); // 13:00–17:00 Riyadh
        $this->availability()->assertJsonPath('data.unavailable_dates', []);

        $this->submit($this->directAt($this->noura, '2026-12-06', '14:00'))->assertStatus(409);
        $this->submit($this->directAt($this->noura, '2026-12-06', '12:30', 31))->assertStatus(409); // 1 min into it
        $this->submit($this->directAt($this->noura, '2026-12-06', '12:00', 60))->assertCreated(); // ends at its start
        $this->submit($this->directAt($this->noura, '2026-12-06', '17:00', 60))->assertCreated(); // starts at its end
        $this->submit($this->directAt($this->noura, '2026-12-06', '18:00', 60))->assertCreated();
    }

    public function test_requests_in_the_gap_between_two_partial_blocks_are_created(): void
    {
        $this->storeBlock($this->noura, '2026-12-06T06:00:00Z', '2026-12-06T09:00:00Z'); // 09–12
        $this->storeBlock($this->noura, '2026-12-06T12:00:00Z', '2026-12-06T15:00:00Z'); // 15–18
        $this->submit($this->directAt($this->noura, '2026-12-06', '12:00', 180))->assertCreated();
    }

    public function test_midnight_crossing_window_hits_a_block_on_the_next_day(): void
    {
        $this->storeBlock($this->noura, '2026-12-06T21:00:00Z', '2026-12-07T21:00:00Z'); // whole 12-07
        $this->submit($this->directAt($this->noura, '2026-12-06', '23:30', 120))->assertStatus(409);
        $this->submit($this->directAt($this->noura, '2026-12-06', '22:00', 120))->assertCreated(); // ends 00:00
    }

    public function test_year_end_block_does_not_touch_new_year(): void
    {
        $this->storeBlock($this->noura, '2026-12-30T21:00:00Z', '2026-12-31T21:00:00Z');
        $this->submit($this->directAt($this->noura, '2027-01-01', '00:30'))->assertCreated();
        $this->submit($this->directAt($this->noura, '2026-12-31', '23:30', 30))->assertStatus(409);
    }

    public function test_after_unblocking_the_request_succeeds_and_the_same_key_is_reusable_after_409(): void
    {
        $block = $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $key = (string) Str::uuid();
        $body = $this->directAt($this->noura, '2026-12-05', '19:00');

        $this->submit($body, $key)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->submit($body, $key)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');

        $this->asProvider($this->noura)->deleteJson(self::BLOCKS."/{$block->id}")->assertOk();
        $id = $this->submit($body, $key)->assertCreated()->json('data.id');
        $this->assertSame(1, ServiceRequest::query()->count());

        // Replay after the provider blocked that time again: the original request (§8).
        $this->block($this->noura, ['date' => '2026-12-05'])->assertCreated()->assertJsonPath('data.conflicting_requests.0.id', $id);
        $this->submit($body, $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, ServiceRequest::query()->count());
    }

    // QA fix 2026-11-19 (BUG-007, supersedes D82): her weekly hours restrict direct requests and unavailable dates.
    public function test_working_hours_refuse_requests_outside_them(): void
    {
        $this->noura->workingHours()->create(['weekday' => 0, 'opens_at' => '09:00', 'closes_at' => '23:00']);
        $this->submit($this->directAt($this->noura, '2026-12-06', '22:30'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE'); // Sunday, ends 23:30 after closing
        $this->submit($this->directAt($this->noura, '2026-12-07', '19:00'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE'); // Monday: closed
        $this->submit($this->directAt($this->noura, '2026-12-06', '19:00'))->assertCreated();
    }

    public function test_closed_weekdays_are_unavailable_dates(): void
    {
        $this->noura->workingHours()->create(['weekday' => 0, 'opens_at' => '09:00', 'closes_at' => '23:00']);
        $dates = app(\App\Services\Availability\ProviderAvailability::class)->unavailableDates($this->noura, '2026-12-06', '2026-12-08');
        $this->assertSame(['2026-12-07', '2026-12-08'], $dates);
    }

    public function test_no_working_hours_means_no_limit(): void
    {
        $this->submit($this->directAt($this->noura, '2026-12-07', '22:00'))->assertCreated();
    }

    public function test_hidden_provider_is_still_the_visibility_422_first(): void
    {
        $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $this->noura->update(['status' => ProviderStatus::Suspended]);
        $this->submit($this->directAt($this->noura, '2026-12-05', '19:00'))->assertUnprocessable()->assertJsonValidationErrors(['provider_id'], 'errors');
    }

    // ── Open requests (D84) ─────────────────────────────────────────────────────────────────

    public function test_open_request_in_a_block_still_reaches_her_with_calendar_conflict(): void
    {
        $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $request = $this->created($this->openBody(['event_date' => '2026-12-05']));

        $this->assertTrue($request->recipients()->where('provider_id', $this->noura->id)->exists());
        $this->asProvider($this->noura)->getJson("/api/v1/provider/service-requests/{$request->id}")
            ->assertOk()->assertJsonPath('data.calendar_conflict', true);
        $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}")
            ->assertOk()->assertJsonMissingPath('data.calendar_conflict');
    }
}

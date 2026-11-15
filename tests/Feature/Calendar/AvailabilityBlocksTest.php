<?php

namespace Tests\Feature\Calendar;

use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Models\ActivityEvent;
use App\Models\AvailabilityBlock;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAvailability;
use Tests\TestCase;

/**
 * Slice 5a §5: block create / delete, calendar contents, validation boundaries and provider statuses (D83–D99).
 */
class AvailabilityBlocksTest extends TestCase
{
    use BuildsAvailability, RefreshDatabase;

    private const CALENDAR = '/api/v1/provider/calendar';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    // ── Create ──────────────────────────────────────────────────────────────────────────────

    public function test_blocks_a_whole_riyadh_day(): void
    {
        $this->block($this->noura, ['date' => '2026-12-05', 'reason' => 'Family event'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.starts_at', '2026-12-04T21:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-12-05T21:00:00Z')
            ->assertJsonPath('data.from_date', '2026-12-05')
            ->assertJsonPath('data.to_date', '2026-12-05')
            ->assertJsonPath('data.whole_days', true)
            ->assertJsonPath('data.reason', 'Family event')
            ->assertJsonPath('data.created_at', self::NOW)
            ->assertJsonPath('data.conflicting_requests', []);

        $block = AvailabilityBlock::query()->sole();
        $this->assertSame($this->noura->id, $block->provider_id);
        $this->assertSame($this->noura->user_id, $block->created_by);

        $event = ActivityEvent::query()->where('event', 'availability_blocked')->sole();
        $this->assertSame('internal', $event->visibility->value);
        $this->assertSame($this->noura->getMorphClass(), $event->subject_type);
        $this->assertSame($this->noura->id, $event->subject_id);
        $this->assertEquals(['block_id' => $block->id, 'starts_at' => '2026-12-04T21:00:00Z', 'ends_at' => '2026-12-05T21:00:00Z'], $event->meta);
    }

    public function test_blocks_a_time_range_and_converts_offsets_to_utc(): void
    {
        $this->block($this->noura, ['starts_at' => '2026-12-06T13:00:00+03:00', 'ends_at' => '2026-12-06T17:00:00+03:00', 'reason' => ''])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-12-06T10:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-12-06T14:00:00Z')
            ->assertJsonPath('data.from_date', '2026-12-06')
            ->assertJsonPath('data.to_date', '2026-12-06')
            ->assertJsonPath('data.whole_days', false)
            ->assertJsonPath('data.reason', null);
    }

    public function test_today_is_accepted_and_yesterday_is_refused(): void
    {
        $this->block($this->noura, ['date' => '2026-12-01'])->assertCreated();
        $this->block($this->noura, ['date' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors(['date'], 'errors');
    }

    public function test_form_validation(): void
    {
        $both = $this->block($this->noura, ['date' => '2026-12-05', 'starts_at' => '2026-12-06T10:00:00Z', 'ends_at' => '2026-12-06T14:00:00Z']);
        $both->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.date.0', 'Send either a date or a start and end time, not both.');

        $this->block($this->noura, [])->assertJsonValidationErrors(['date'], 'errors');
        $this->block($this->noura, ['ends_at' => '2026-12-06T14:00:00Z'])->assertJsonValidationErrors(['starts_at'], 'errors');
        $this->block($this->noura, ['starts_at' => '2026-12-06T10:00:00Z'])->assertJsonValidationErrors(['ends_at'], 'errors');
        $this->block($this->noura, ['date' => '05-12-2026'])->assertJsonValidationErrors(['date'], 'errors');
        $this->block($this->noura, ['date' => '2026-02-30'])->assertJsonValidationErrors(['date'], 'errors');
        $this->block($this->noura, ['starts_at' => '2026-12-06T10:00:00', 'ends_at' => '2026-12-06T14:00:00Z'])->assertJsonValidationErrors(['starts_at'], 'errors');
        $this->block($this->noura, ['starts_at' => '2026-12-06T10:00:30Z', 'ends_at' => '2026-12-06T14:00:00Z'])->assertJsonValidationErrors(['starts_at'], 'errors');
        $this->block($this->noura, ['starts_at' => '2026-12-06T14:00:00Z', 'ends_at' => '2026-12-06T14:00:00Z'])->assertJsonValidationErrors(['ends_at'], 'errors');
        $this->block($this->noura, ['date' => '2026-12-05', 'reason' => str_repeat('a', 256)])->assertJsonValidationErrors(['reason'], 'errors');

        $this->assertSame(0, AvailabilityBlock::query()->count());
    }

    public function test_a_range_already_over_is_refused_but_a_running_one_is_allowed(): void
    {
        $this->block($this->noura, ['starts_at' => '2026-11-30T09:00:00Z', 'ends_at' => '2026-12-01T09:00:00Z'])
            ->assertJsonValidationErrors(['ends_at'], 'errors');
        $this->block($this->noura, ['starts_at' => '2026-11-30T09:00:00Z', 'ends_at' => '2026-12-01T09:01:00Z'])->assertCreated();
    }

    public function test_block_length_limit_is_527040_minutes(): void
    {
        $this->block($this->noura, ['starts_at' => '2026-12-02T00:00:00Z', 'ends_at' => '2027-12-03T00:00:01Z'])->assertUnprocessable();
        // 2026-12-02 → 2027-12-03 = 366 days = 527 040 minutes.
        $this->block($this->noura, ['starts_at' => '2026-12-02T00:00:00Z', 'ends_at' => '2027-12-03T00:01:00Z'])
            ->assertJsonValidationErrors(['ends_at'], 'errors');
        $this->block($this->noura, ['starts_at' => '2026-12-02T00:00:00Z', 'ends_at' => '2027-12-03T00:00:00Z'])->assertCreated();
    }

    public function test_overlapping_blocks_are_refused_and_touching_ones_allowed(): void
    {
        $this->block($this->noura, ['date' => '2026-12-05'])->assertCreated();

        $this->block($this->noura, ['date' => '2026-12-05'])
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => 'This period overlaps a block you already have.', 'code' => 'BLOCK_OVERLAP', 'errors' => []]);
        $this->block($this->noura, ['starts_at' => '2026-12-05T10:00:00Z', 'ends_at' => '2026-12-05T11:00:00Z'])->assertStatus(409);
        $this->block($this->noura, ['starts_at' => '2026-12-04T20:00:00Z', 'ends_at' => '2026-12-04T21:01:00Z'])->assertStatus(409);

        // Touching on both sides: not an overlap (§3.2), not merged (D85).
        $this->block($this->noura, ['starts_at' => '2026-12-04T20:00:00Z', 'ends_at' => '2026-12-04T21:00:00Z'])->assertCreated();
        $this->block($this->noura, ['starts_at' => '2026-12-05T21:00:00Z', 'ends_at' => '2026-12-05T22:00:00Z'])->assertCreated();
        $this->assertSame(3, AvailabilityBlock::query()->count());

        // Another provider's block never counts.
        $this->block($this->matchingProvider('Huda Studio'), ['date' => '2026-12-05'])->assertCreated();
    }

    public function test_conflicting_requests_are_listed_and_left_unchanged(): void
    {
        $request = $this->created($this->directAt($this->noura, '2026-12-05', '19:00', 180));
        $other = $this->created($this->directAt($this->noura, '2026-12-07', '19:00', 60));

        $response = $this->block($this->noura, ['date' => '2026-12-05'])->assertCreated()
            ->assertJsonCount(1, 'data.conflicting_requests')
            ->assertJsonPath('data.conflicting_requests.0.id', $request->id)
            ->assertJsonPath('data.conflicting_requests.0.calendar_conflict', true)
            ->assertJsonPath('data.conflicting_requests.0.status', 'new')
            ->assertJsonPath('data.conflicting_requests.0.starts_at', '2026-12-05T16:00:00Z');
        $this->assertSame(
            ['id', 'reference', 'type', 'type_label', 'request_status', 'request_status_label', 'status', 'status_label', 'service', 'event_date', 'start_time', 'duration_minutes', 'starts_at', 'ends_at', 'event_passed', 'city', 'calendar_conflict'],
            array_keys($response->json('data.conflicting_requests.0')),
        );

        $this->assertSame('awaiting_offers', $request->refresh()->status->value);
        $this->assertSame('new', $request->recipients()->sole()->status->value);
        $this->assertNotSame($other->id, $request->id);
    }

    // ── Delete ──────────────────────────────────────────────────────────────────────────────

    public function test_delete_is_a_soft_delete_and_a_new_block_may_replace_it(): void
    {
        $id = $this->block($this->noura, ['date' => '2026-12-05'])->json('data.id');

        $this->asProvider($this->noura)->deleteJson(self::BLOCKS."/{$id}")
            ->assertOk()->assertExactJson(['success' => true, 'data' => null, 'message' => null, 'meta' => []]);

        $row = AvailabilityBlock::withTrashed()->findOrFail($id);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame($this->noura->user_id, $row->deleted_by);
        $this->assertSame(1, ActivityEvent::query()->where('event', 'availability_unblocked')->where('meta->block_id', $id)->count());

        $this->asProvider($this->noura)->deleteJson(self::BLOCKS."/{$id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

        $newId = $this->block($this->noura, ['date' => '2026-12-05'])->assertCreated()->json('data.id');
        $this->assertNotSame($id, $newId);
        $this->assertSame(2, AvailabilityBlock::withTrashed()->count());
    }

    public function test_another_providers_or_unknown_block_is_404(): void
    {
        $huda = $this->matchingProvider('Huda Studio');
        $id = $this->block($huda, ['date' => '2026-12-05'])->json('data.id');

        $this->asProvider($this->noura)->deleteJson(self::BLOCKS."/{$id}")->assertNotFound();
        $this->asProvider($this->noura)->deleteJson(self::BLOCKS.'/999999')->assertNotFound();
        $this->assertNull(AvailabilityBlock::query()->findOrFail($id)->deleted_at);
    }

    public function test_a_past_block_can_be_deleted(): void
    {
        $block = $this->storeBlock($this->noura, '2026-11-20T21:00:00Z', '2026-11-21T21:00:00Z');
        $this->asProvider($this->noura)->deleteJson(self::BLOCKS."/{$block->id}")->assertOk();
    }

    // ── Calendar ────────────────────────────────────────────────────────────────────────────

    public function test_calendar_lists_active_blocks_and_open_requests_in_range(): void
    {
        $huda = $this->matchingProvider('Huda Studio');
        $second = $this->created($this->directAt($this->noura, '2026-12-08', '19:00'));
        $first = $this->created($this->directAt($this->noura, '2026-12-05', '10:00')); // inside her block
        $declined = $this->created($this->directAt($this->noura, '2026-12-09', '19:00'));
        $declined->recipients()->update(['status' => 'declined', 'declined_at' => now()]);
        $cancelled = $this->created($this->directAt($this->noura, '2026-12-12', '19:00'));
        $cancelled->update(['status' => 'cancelled']);
        $this->created($this->directAt($huda, '2026-12-08', '19:00'));

        $late = $this->storeBlock($this->noura, '2026-12-09T21:00:00Z', '2026-12-10T21:00:00Z');
        $early = $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $deleted = $this->storeBlock($this->noura, '2026-12-06T21:00:00Z', '2026-12-07T21:00:00Z');
        $deleted->delete();
        $this->storeBlock($this->noura, '2027-01-10T21:00:00Z', '2027-01-11T21:00:00Z'); // out of range
        $this->storeBlock($huda, '2026-12-11T21:00:00Z', '2026-12-12T21:00:00Z'); // another provider

        $this->asProvider($this->noura)->getJson(self::CALENDAR.'?from=2026-12-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-12-01')
            ->assertJsonPath('data.to', '2026-12-31')
            ->assertJsonCount(2, 'data.blocks')
            ->assertJsonPath('data.blocks.0.id', $early->id)
            ->assertJsonPath('data.blocks.1.id', $late->id)
            ->assertJsonCount(2, 'data.requests')
            ->assertJsonPath('data.requests.0.id', $first->id)
            ->assertJsonPath('data.requests.0.calendar_conflict', true)
            ->assertJsonPath('data.requests.1.id', $second->id)
            ->assertJsonPath('data.requests.1.calendar_conflict', false)
            ->assertJsonMissingPath('data.requests.0.client')
            ->assertJsonMissingPath('data.requests.0.district')
            ->assertJsonMissingPath('data.blocks.0.deleted_at');

        // The range edge is the Riyadh day: a block ending at 00:00 Riyadh on `from` is outside.
        $this->asProvider($this->noura)->getJson(self::CALENDAR.'?from=2026-12-06&to=2026-12-09')
            ->assertJsonCount(0, 'data.blocks');
        $this->asProvider($this->noura)->getJson(self::CALENDAR.'?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonCount(0, 'data.blocks'); // past `from` allowed
    }

    public function test_calendar_range_validation(): void
    {
        $as = fn () => $this->asProvider($this->noura);
        $as()->getJson(self::CALENDAR.'?from=2026-12-01&to=2027-01-31')->assertOk(); // 62 dates
        $as()->getJson(self::CALENDAR.'?from=2026-12-01&to=2027-02-01')->assertJsonValidationErrors(['to'], 'errors'); // 63
        $as()->getJson(self::CALENDAR.'?from=2026-12-05&to=2026-12-04')->assertJsonValidationErrors(['to'], 'errors');
        $as()->getJson(self::CALENDAR.'?from=2026-12-05&to=2026-12-05')->assertOk();
        $as()->getJson(self::CALENDAR)->assertJsonValidationErrors(['from', 'to'], 'errors');
        $as()->getJson(self::CALENDAR.'?from=12/01/2026&to=2026-12-05')->assertJsonValidationErrors(['from'], 'errors');
    }

    // ── calendar_conflict on the inbox (§6) ─────────────────────────────────────────────────

    public function test_inbox_carries_calendar_conflict_on_list_and_detail(): void
    {
        $inside = $this->created($this->directAt($this->noura, '2026-12-05', '13:00'));
        $outside = $this->created($this->directAt($this->noura, '2026-12-05', '18:00'));
        $declined = $this->created($this->directAt($this->noura, '2026-12-05', '14:00'));
        $declined->recipients()->update(['status' => 'declined', 'declined_at' => now()]);
        $block = $this->storeBlock($this->noura, '2026-12-05T09:00:00Z', '2026-12-05T13:00:00Z'); // 12:00–16:00 Riyadh

        $flags = fn () => collect($this->asProvider($this->noura)->getJson('/api/v1/provider/service-requests')->assertOk()->json('data'))
            ->pluck('calendar_conflict', 'id')->all();
        $this->assertTrue($flags()[$inside->id]);
        $this->assertFalse($flags()[$outside->id]);
        $this->assertFalse($flags()[$declined->id]);
        $this->asProvider($this->noura)->getJson("/api/v1/provider/service-requests/{$inside->id}")->assertJsonPath('data.calendar_conflict', true);

        $block->delete();
        $this->assertFalse($flags()[$inside->id]);
        $this->asProvider($this->noura)->getJson("/api/v1/provider/service-requests/{$inside->id}")->assertJsonPath('data.calendar_conflict', false);
    }

    // ── Statuses and audiences ──────────────────────────────────────────────────────────────

    public function test_provider_suspended_can_view_and_delete_but_not_create(): void
    {
        $this->created($this->directAt($this->noura, '2026-12-05', '19:00'));
        $block = $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $kept = $this->storeBlock($this->noura, '2026-12-09T21:00:00Z', '2026-12-10T21:00:00Z');
        $this->noura->update(['status' => ProviderStatus::Suspended]);

        $this->asProvider($this->noura)->getJson(self::CALENDAR.'?from=2026-12-01&to=2026-12-31')
            ->assertOk()->assertJsonCount(2, 'data.blocks')->assertJsonPath('data.requests', []);
        $this->block($this->noura, ['date' => '2026-12-20'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->asProvider($this->noura)->deleteJson(self::BLOCKS."/{$block->id}")->assertOk();
        $this->assertSame(1, ActivityEvent::query()->where('event', 'availability_unblocked')->count());
        $this->getJson("/api/v1/shared/providers/{$this->noura->id}/availability?from=2026-12-01&to=2026-12-31")->assertNotFound();

        // Blocks are untouched by the suspension and apply again on reactivation (D96).
        $this->assertNull($kept->refresh()->deleted_at);
        $this->noura->update(['status' => ProviderStatus::Active]);
        $this->getJson("/api/v1/shared/providers/{$this->noura->id}/availability?from=2026-12-01&to=2026-12-31")
            ->assertOk()->assertJsonPath('data.unavailable_dates', ['2026-12-10']);
        $this->submit($this->directAt($this->noura, '2026-12-10', '19:00'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    }

    public function test_providers_not_yet_active_get_409_on_all_three(): void
    {
        foreach ([ProviderStatus::Draft, ProviderStatus::Submitted, ProviderStatus::NeedsCompletion] as $status) {
            $provider = $this->provider("P {$status->value}", [], ['status' => $status, 'activated_at' => null]);
            $block = $this->storeBlock($provider, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');

            $this->asProvider($provider)->getJson(self::CALENDAR.'?from=2026-12-01&to=2026-12-31')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
            $this->block($provider, ['date' => '2026-12-20'])->assertStatus(409);
            $this->asProvider($provider)->deleteJson(self::BLOCKS."/{$block->id}")->assertStatus(409);
        }
    }

    public function test_account_suspended_wrong_audience_and_no_token(): void
    {
        $block = $this->storeBlock($this->noura, '2026-12-04T21:00:00Z', '2026-12-05T21:00:00Z');
        $token = $this->tokenFor($this->noura->user);
        $this->noura->user->update(['status' => UserStatus::Suspended]);

        foreach ([['get', self::CALENDAR.'?from=2026-12-01&to=2026-12-31'], ['post', self::BLOCKS], ['delete', self::BLOCKS."/{$block->id}"]] as [$method, $url]) {
            $this->withApiToken($token)->json($method, $url, ['date' => '2026-12-20'])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
            $this->actingAsApi($this->client)->json($method, $url, ['date' => '2026-12-20'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
            $this->actingAsApi(User::factory()->admin()->create())->json($method, $url, ['date' => '2026-12-20'])->assertForbidden();
            $this->app['tymon.jwt']->unsetToken();
            $this->app['auth']->forgetGuards();
            $this->flushHeaders()->json($method, $url, ['date' => '2026-12-20'])->assertUnauthorized();
        }
        $this->assertNull($block->refresh()->deleted_at);
    }
}

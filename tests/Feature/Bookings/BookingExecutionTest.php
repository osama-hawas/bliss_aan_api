<?php

namespace Tests\Feature\Bookings;

use App\Enums\BookingStatus;
use App\Enums\ProviderStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\ActivityEvent;
use App\Models\Booking;
use App\Models\Provider;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 6 §3 (D125, D126): the booked provider starts from 60 minutes before the event (admin setting), with no
 * upper limit, and confirms completion herself; completion is idempotent.
 */
class BookingExecutionTest extends TestCase
{
    use BuildsBookings, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    public function test_start_inside_the_window_moves_booking_and_request_to_in_progress(): void
    {
        $booking = $this->booking($this->noura);
        $this->at('2026-12-05T15:00:00Z'); // exactly 60 minutes before

        $res = $this->startBooking($this->noura, $booking)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.started_at', '2026-12-05T15:00:00Z')
            ->assertJsonPath('data.actions.can_start', false)
            ->assertJsonPath('data.actions.can_complete', true)
            ->assertJsonPath('data.actions.can_request_cancellation', false);

        $booking->refresh();
        $this->assertSame(BookingStatus::InProgress, $booking->status);
        $this->assertSame($this->noura->user_id, $booking->started_by);
        $this->assertSame(ServiceRequestStatus::InProgress, $booking->serviceRequest->status);
        $this->assertTrue(ActivityEvent::query()->where('event', 'booking_started')->where('subject_id', $booking->service_request_id)->exists());
        $this->assertArrayNotHasKey('started_by', $res->json('data'));
    }

    public function test_start_before_the_window_is_refused(): void
    {
        $booking = $this->booking($this->noura);
        $this->at('2026-12-05T14:59:00Z');

        $this->asProvider($this->noura)->getJson("/api/v1/provider/bookings/{$booking->id}")
            ->assertJsonPath('data.actions.can_start', false)
            ->assertJsonPath('data.start_available_at', '2026-12-05T15:00:00Z');
        $this->startBooking($this->noura, $booking)->assertStatus(409)->assertJsonPath('code', 'START_TOO_EARLY');
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_start_after_the_scheduled_time_is_allowed(): void
    {
        $booking = $this->booking($this->noura);
        $this->at('2026-12-06T10:00:00Z'); // the event ended hours ago

        $this->startBooking($this->noura, $booking)->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    public function test_the_window_follows_the_admin_setting(): void
    {
        app(AppSettings::class)->set('bookings.start_early_minutes', 120);
        $booking = $this->booking($this->noura);
        $this->at('2026-12-05T14:00:00Z');

        $this->startBooking($this->noura, $booking)->assertOk();
    }

    public function test_another_provider_gets_404(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);

        $this->startBooking($this->huda, $booking)->assertNotFound();
        $this->completeBooking($this->huda, $booking)->assertNotFound();
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_a_client_cannot_use_the_provider_routes(): void
    {
        $booking = $this->booking($this->noura);
        $this->asClient()->postJson("/api/v1/provider/bookings/{$booking->id}/start")->assertForbidden();
    }

    public function test_a_suspended_provider_cannot_start(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);
        $this->noura->update(['status' => ProviderStatus::Suspended]);

        $this->startBooking($this->noura, $booking)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_a_repeated_start_is_refused(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();

        $this->startBooking($this->noura, $booking)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->assertSame(1, ActivityEvent::query()->where('event', 'booking_started')->count());
    }

    public function test_start_is_blocked_while_a_cancellation_is_under_review(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $this->at(self::EVENT_STARTS);

        $this->startBooking($this->noura, $booking)->assertStatus(409)->assertJsonPath('code', 'CANCELLATION_PENDING');
    }

    public function test_completion_by_the_booked_provider(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();
        $this->at('2026-12-05T19:05:00Z');

        $this->completeBooking($this->noura, $booking)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', '2026-12-05T19:05:00Z')
            ->assertJsonPath('data.actions.can_complete', false);

        $booking->refresh();
        $this->assertSame($this->noura->user_id, $booking->completed_by);
        $this->assertSame(ServiceRequestStatus::Completed, $booking->serviceRequest->status);
        // The slot is free again (D110).
        $this->assertSame([], Booking::query()->busy()->pluck('id')->all());
    }

    public function test_completing_a_confirmed_booking_is_refused(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);

        $this->completeBooking($this->noura, $booking)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_completion_is_idempotent(): void
    {
        $booking = $this->completedBooking($this->noura);
        $completedAt = $booking->completed_at->toIso8601ZuluString();
        $this->at('2026-12-06T09:00:00Z');

        $this->completeBooking($this->noura, $booking)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', $completedAt);
        $this->assertSame(1, ActivityEvent::query()->where('event', 'booking_completed')->count());
    }

    public function test_the_client_sees_progress_but_no_provider_actions(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking);

        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.actions.can_start', false)
            ->assertJsonPath('data.actions.can_complete', false)
            ->assertJsonMissingPath('data.start_available_at');
    }
}

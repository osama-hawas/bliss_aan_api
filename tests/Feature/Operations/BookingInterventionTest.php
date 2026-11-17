<?php

namespace Tests\Feature\Operations;

use App\Enums\BookingStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\ActivityEvent;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §5.2 (D256, D257): staff cancel / complete / note on a booking, with reason, locks, audit,
 * history and E23 / E24 to both parties.
 */
class BookingInterventionTest extends TestCase
{
    use BuildsBookings, BuildsNotifications, BuildsOperations, RefreshDatabase;

    private Provider $noura;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->staff = $this->ops();
    }

    private function act(Booking $booking, string $action, array $body = [], ?User $as = null): TestResponse
    {
        return $this->asStaff($as ?? $this->staff)->postJson("/api/v1/admin/bookings/{$booking->id}/{$action}",
            [...['reason' => 'The provider cannot attend; agreed with the client.'], ...$body]);
    }

    public function test_staff_cancel_a_confirmed_booking(): void
    {
        $booking = $this->booking($this->noura);

        $this->act($booking, 'cancel', ['internal_note' => 'Called both.'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_requests.0.requested_by', 'staff')
            ->assertJsonPath('data.cancellation_requests.0.status', 'accepted')
            ->assertJsonPath('data.cancellation_requests.0.decision_reason', 'The provider cannot attend; agreed with the client.')
            ->assertJsonPath('data.cancellation_requests.0.internal_note', 'Called both.')
            ->assertJsonPath('data.actions.can_cancel', false)
            ->assertJsonPath('data.payment', null);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame(ServiceRequestStatus::Cancelled, $booking->serviceRequest->status);
        $c = CancellationRequest::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame($this->staff->id, $c->decided_by);
        $this->assertNull($c->open_booking_id);
        $this->assertSame('booking.cancelled_by_staff', AuditLog::query()->latest('id')->value('action'));
        $this->assertTrue(ActivityEvent::query()->where('event', 'booking_cancelled_by_staff')->where('visibility', 'all')->exists());
        $this->assertTrue(ActivityEvent::query()->where('event', 'staff_note')->where('visibility', 'internal')->exists());
        $this->assertContains('booking_cancelled_by_staff', $this->events($this->client));
        $this->assertContains('booking_cancelled_by_staff', $this->events($this->noura->user_id));
        $this->assertSame(0, Booking::query()->busy()->count()); // the slot is free again
    }

    public function test_the_parties_see_the_staff_cancellation_without_the_internal_note(): void
    {
        $booking = $this->booking($this->noura);
        $this->act($booking, 'cancel', ['internal_note' => 'Secret'])->assertOk();

        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.cancellation_requests.0.requested_by', 'staff')
            ->assertJsonPath('data.cancellation_requests.0.requested_by_label', 'Bliss aan support')
            ->assertJsonPath('data.cancellation_requests.0.is_mine', false)
            ->assertJsonMissingPath('data.cancellation_requests.0.internal_note');
    }

    public function test_staff_cancel_an_in_progress_booking(): void
    {
        $booking = $this->inProgress($this->noura, $this->booking($this->noura));

        $this->act($booking, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancel_is_refused_while_a_party_request_is_pending_or_after_the_end(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $this->act($booking, 'cancel')->assertStatus(409)->assertJsonPath('code', 'CANCELLATION_PENDING');

        $done = $this->completedBooking($this->matchingProvider('Huda Studio'));
        $this->act($done, 'cancel')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_reason_is_required(): void
    {
        $booking = $this->booking($this->noura);
        $this->act($booking, 'cancel', ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->act($booking, 'cancel', ['reason' => 'ab'])->assertStatus(422);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_staff_complete_an_in_progress_booking(): void
    {
        $booking = $this->inProgress($this->noura, $this->booking($this->noura));

        $this->act($booking, 'complete')->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_by.id', $this->staff->id);
        $this->assertSame(ServiceRequestStatus::Completed, $booking->refresh()->serviceRequest->status);
        $this->assertSame('booking.completed_by_staff', AuditLog::query()->latest('id')->value('action'));
        $this->assertContains('booking_completed_by_staff', $this->events($this->client));
        $this->assertContains('booking_completed_by_staff', $this->events($this->noura->user_id));
        $this->assertNotContains('booking_completed', $this->events($this->client)); // E24 replaces E11

        // The client can now review (Slice 6 rule); a second completion is idempotent.
        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")->assertJsonPath('data.actions.can_review', true);
        $this->act($booking, 'complete')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_complete_needs_an_in_progress_booking(): void
    {
        $this->act($this->booking($this->noura), 'complete')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_internal_note(): void
    {
        $booking = $this->booking($this->noura);

        $this->asStaff($this->staff)->postJson("/api/v1/admin/bookings/{$booking->id}/notes", ['note' => 'Client called.'])
            ->assertCreated()->assertJsonPath('data.staff_notes.0.note', 'Client called.')
            ->assertJsonPath('data.staff_notes.0.author.id', $this->staff->id);
        $this->asClient()->getJson("/api/v1/client/service-requests/{$booking->service_request_id}")->assertOk()
            ->assertJsonMissing(['event' => 'staff_note']);
        $this->assertSame(0, DB::table('notifications')->where('type', 'like', '%staff%')->count()); // notes notify nobody
    }

    public function test_permissions(): void
    {
        $booking = $this->booking($this->noura);
        $viewer = $this->staffWith(['bookings.view']);

        $this->act($booking, 'cancel', [], $viewer)->assertForbidden();
        $this->act($booking, 'complete', [], $viewer)->assertForbidden();
        $this->asStaff($viewer)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.actions.can_cancel', false)
            ->assertJsonPath('data.actions.can_add_note', false);
        $this->asClient()->postJson("/api/v1/admin/bookings/{$booking->id}/cancel", ['reason' => 'Nope nope'])->assertForbidden();
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }
}

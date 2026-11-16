<?php

namespace Tests\Feature\Bookings;

use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\CancellationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\ActivityEvent;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 6 §4 (D127, D128, D131): client cancellation and provider inability, reason + explanation required, one
 * pending request per booking, withdrawal by the requester, admin approve/reject.
 */
class BookingCancellationTest extends TestCase
{
    use BuildsApplications, BuildsBookings, RefreshDatabase;

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

    private function decide(CancellationRequest $c, string $decision, ?User $admin = null, array $body = []): TestResponse
    {
        return $this->actingAsApi($admin ?? $this->staff(AdminRole::OperationsSupport))->withHeader('Accept-Language', 'en')
            ->postJson("/api/v1/admin/cancellation-requests/{$c->id}/decide", [...['decision' => $decision, 'decision_reason' => 'Checked with both parties.'], ...$body]);
    }

    private function pending(Booking $booking): CancellationRequest
    {
        return CancellationRequest::query()->where('booking_id', $booking->id)->latest('id')->firstOrFail();
    }

    // ── Client ──────────────────────────────────────────────────────────────────────────────

    public function test_client_asks_to_cancel_and_the_booking_stays_active(): void
    {
        $booking = $this->booking($this->noura);

        $this->clientCancel($booking)->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.cancellation_requests.0.status', 'under_review')
            ->assertJsonPath('data.cancellation_requests.0.requested_by', 'client')
            ->assertJsonPath('data.cancellation_requests.0.reason_code', 'event_date_changed')
            ->assertJsonPath('data.cancellation_requests.0.reason_label', 'The event date changed')
            ->assertJsonPath('data.cancellation_requests.0.reason_text', 'We moved the wedding.')
            ->assertJsonPath('data.cancellation_requests.0.is_mine', true)
            ->assertJsonPath('data.actions.can_request_cancellation', false)
            ->assertJsonPath('data.actions.can_withdraw_cancellation', true)
            ->assertJsonMissingPath('data.cancellation_requests.0.internal_note');

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertSame(1, Booking::query()->busy()->count()); // keeps its slot
        // The provider sees it, not as hers.
        $this->asProvider($this->noura)->getJson("/api/v1/provider/bookings/{$booking->id}")
            ->assertJsonPath('data.cancellation_requests.0.is_mine', false)
            ->assertJsonPath('data.actions.can_withdraw_cancellation', false)
            ->assertJsonPath('data.actions.can_request_cancellation', false);
        $this->assertTrue(ActivityEvent::query()->where('event', 'cancellation_requested')->exists());
    }

    public function test_reason_and_explanation_are_required(): void
    {
        $booking = $this->booking($this->noura);

        $this->clientCancel($booking, ['reason_code' => null])->assertUnprocessable()->assertJsonValidationErrors('reason_code', 'errors');
        $this->clientCancel($booking, ['reason_text' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason_text', 'errors');
        $this->clientCancel($booking, ['reason_text' => str_repeat('a', 1001)])->assertUnprocessable();
        $this->clientCancel($booking, ['reason_code' => 'nonsense'])->assertUnprocessable();
        // A provider-only code is refused for the client, and the other way round.
        $this->clientCancel($booking, ['reason_code' => 'emergency'])->assertUnprocessable()->assertJsonValidationErrors('reason_code', 'errors');
        $this->providerCancel($this->noura, $booking, ['reason_code' => 'event_date_changed'])->assertUnprocessable();
        $this->assertSame(0, CancellationRequest::query()->count());
    }

    public function test_only_one_pending_request_per_booking_from_either_side(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();

        $this->clientCancel($booking)->assertStatus(409)->assertJsonPath('code', 'CANCELLATION_PENDING');
        $this->providerCancel($this->noura, $booking)->assertStatus(409)->assertJsonPath('code', 'CANCELLATION_PENDING');
    }

    public function test_not_allowed_once_the_service_started(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);
        $this->startBooking($this->noura, $booking)->assertOk();

        $this->clientCancel($booking)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->providerCancel($this->noura, $booking)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_other_users_get_404(): void
    {
        $booking = $this->booking($this->noura);
        $stranger = User::factory()->client()->create();

        $this->asClient($stranger)->postJson("/api/v1/client/bookings/{$booking->id}/cancellation-requests", ['reason_code' => 'other', 'reason_text' => 'x'])->assertNotFound();
        $this->providerCancel($this->huda, $booking)->assertNotFound();
    }

    public function test_reason_lists(): void
    {
        $this->asClient()->getJson('/api/v1/client/cancellation-reasons')->assertOk()
            ->assertJsonPath('data.*.code', ['event_date_changed', 'requirements_changed', 'other']);
        $this->asProvider($this->noura)->getJson('/api/v1/provider/cancellation-reasons')->assertOk()
            ->assertJsonPath('data.*.code', ['unable_to_deliver', 'emergency', 'other']);
    }

    // ── Withdraw ────────────────────────────────────────────────────────────────────────────

    public function test_the_requester_withdraws_and_may_ask_again(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $c = $this->pending($booking);

        $this->providerWithdraw($c)->assertNotFound(); // not hers
        $this->asClient()->postJson("/api/v1/client/cancellation-requests/{$c->id}/withdraw")->assertOk()
            ->assertJsonPath('data.cancellation_requests.0.status', 'withdrawn')
            ->assertJsonPath('data.actions.can_request_cancellation', true);
        $this->asClient()->postJson("/api/v1/client/cancellation-requests/{$c->id}/withdraw")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->clientCancel($booking, ['reason_code' => 'requirements_changed'])->assertCreated()
            ->assertJsonCount(2, 'data.cancellation_requests');
    }

    public function test_provider_withdraws_her_inability_report(): void
    {
        $booking = $this->booking($this->noura);
        $this->providerCancel($this->noura, $booking)->assertCreated()
            ->assertJsonPath('data.cancellation_requests.0.requested_by', 'provider');
        $c = $this->pending($booking);

        $this->asClient()->postJson("/api/v1/client/cancellation-requests/{$c->id}/withdraw")->assertNotFound();
        $this->providerWithdraw($c)->assertOk()->assertJsonPath('data.cancellation_requests.0.status', 'withdrawn');
        $this->assertSame(CancellationStatus::Withdrawn, $c->refresh()->status);
        $this->assertNull($c->open_booking_id);
    }

    private function providerWithdraw(CancellationRequest $c): TestResponse
    {
        return $this->asProvider($this->noura)->postJson("/api/v1/provider/cancellation-requests/{$c->id}/withdraw");
    }

    // ── Admin ───────────────────────────────────────────────────────────────────────────────

    public function test_admin_approval_cancels_booking_and_request(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $c = $this->pending($booking);
        $admin = $this->staff(AdminRole::OperationsSupport);

        $this->decide($c, 'accept', $admin, ['internal_note' => 'Called the client.'])->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.decided_by.id', $admin->id)
            ->assertJsonPath('data.internal_note', 'Called the client.')
            ->assertJsonPath('data.booking.status', 'cancelled');

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame(ServiceRequestStatus::Cancelled, $booking->serviceRequest->status);
        $this->assertSame(0, Booking::query()->busy()->count()); // slot freed
        $this->assertTrue(AuditLog::query()->where('action', 'cancellation.accepted')->where('actor_id', $admin->id)->exists());
        $this->assertTrue(ActivityEvent::query()->where('event', 'booking_cancelled')->exists());

        $this->asClient()->getJson("/api/v1/client/bookings/{$booking->id}")
            ->assertJsonPath('data.cancellation_requests.0.decision_reason', 'Checked with both parties.')
            ->assertJsonPath('data.actions.can_request_cancellation', false)
            ->assertJsonMissingPath('data.cancellation_requests.0.internal_note');
    }

    public function test_approved_provider_inability_cancels_and_reopens_nothing(): void
    {
        $booking = $this->booking($this->noura);
        $this->providerCancel($this->noura, $booking, ['reason_code' => 'unable_to_deliver', 'reason_text' => 'Equipment broke.'])->assertCreated();
        $c = $this->pending($booking);

        $this->decide($c, 'accept')->assertOk();

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(ServiceRequestStatus::Cancelled, $booking->serviceRequest->status);
        $this->assertSame(1, Booking::query()->count()); // no replacement booking
        $c->refresh();
        $this->assertSame('Equipment broke.', $c->reason_text);
        $this->assertSame('unable_to_deliver', $c->reason_code->value);
        // The client can send a new request.
        $this->submit($this->openBody(['event_date' => '2026-12-05', 'start_time' => '19:00', 'duration_minutes' => 180]))->assertCreated();
    }

    public function test_admin_rejection_keeps_the_booking(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $c = $this->pending($booking);

        $this->decide($c, 'reject')->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.booking.status', 'confirmed');
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'cancellation.rejected')->exists());
        // A new request is possible after a rejection.
        $this->clientCancel($booking)->assertCreated();
    }

    public function test_a_second_decision_is_refused(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $c = $this->pending($booking);
        $this->decide($c, 'reject')->assertOk();

        $this->decide($c, 'accept')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_deciding_a_withdrawn_request_is_refused(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $c = $this->pending($booking);
        $this->asClient()->postJson("/api/v1/client/cancellation-requests/{$c->id}/withdraw")->assertOk();

        $this->decide($c, 'accept')->assertStatus(409);
    }

    public function test_decision_validation_and_permissions(): void
    {
        $booking = $this->booking($this->noura);
        $this->clientCancel($booking)->assertCreated();
        $c = $this->pending($booking);

        $this->decide($c, 'maybe')->assertUnprocessable();
        $this->decide($c, 'accept', null, ['decision_reason' => ''])->assertUnprocessable();

        $viewer = User::factory()->admin()->create();
        $viewer->givePermissionTo('bookings.view');
        $this->decide($c, 'accept', $viewer)->assertForbidden();
        $this->actingAsApi($viewer)->getJson("/api/v1/admin/cancellation-requests/{$c->id}")->assertOk(); // reading is fine

        $this->decide($c, 'accept', $this->staff(AdminRole::AccountsOfficer))->assertForbidden();
        $this->actingAsApi($this->client)->postJson("/api/v1/admin/cancellation-requests/{$c->id}/decide", ['decision' => 'accept', 'decision_reason' => 'x'])->assertForbidden();
        $this->assertSame(CancellationStatus::UnderReview, $c->refresh()->status);
    }

    public function test_admin_lists_and_request_detail_show_the_trail(): void
    {
        $b1 = $this->booking($this->noura);
        $this->clientCancel($b1)->assertCreated();
        $this->decide($this->pending($b1), 'reject')->assertOk();
        $this->clientCancel($b1)->assertCreated();
        $admin = $this->staff(AdminRole::OperationsSupport);

        $this->actingAsApi($admin)->getJson('/api/v1/admin/cancellation-requests?filter[status]=under_review')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.booking.reference', $b1->reference);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/cancellation-requests?filter[requester_role]=provider')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($admin)->getJson("/api/v1/admin/service-requests/{$b1->service_request_id}")->assertOk()
            ->assertJsonCount(2, 'data.booking.cancellation_requests')
            ->assertJsonPath('data.booking.cancellation_requests.0.status', 'under_review')
            ->assertJsonPath('data.booking.cancellation_requests.1.status', 'rejected')
            ->assertJsonPath('data.booking.cancellation_requests.1.decided_by.id', fn ($id) => $id !== null);
    }
}

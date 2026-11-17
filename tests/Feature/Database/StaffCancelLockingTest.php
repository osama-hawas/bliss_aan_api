<?php

namespace Tests\Feature\Database;

use App\Enums\BookingStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\Provider;
use App\Models\User;
use App\Services\Operations\BookingInterventions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 17 staff cancel (D256) with two real MySQL sessions: it takes the Slice 6 lock order (request → booking → its
 * cancellation requests), so a party's cancellation request or a second staff member holding the request makes it
 * wait, then it decides on what was committed.
 */
class StaffCancelLockingTest extends TestCase
{
    use BuildsBookings, UsesCommittedRows;

    private Provider $noura;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function other()
    {
        return DB::connection('other_session');
    }

    private function cancel(Booking $booking): void
    {
        app(BookingInterventions::class)->cancel($this->admin, $booking->fresh(), 'Provider unavailable', null);
    }

    private function assertApiError(ErrorCode $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code->value}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_staff_cancel_waits_for_a_party_request_then_is_refused(): void
    {
        $booking = $this->booking($this->noura);

        $this->other()->beginTransaction();
        $this->other()->select('select id from service_requests where id = ? for update', [$booking->service_request_id]);
        $this->other()->table('cancellation_requests')->insert([
            'reference' => 'CN-0000001', 'booking_id' => $booking->id, 'requester_id' => $this->client->id, 'requester_role' => 'client',
            'reason_code' => 'other', 'reason_text' => 'x', 'status' => 'under_review', 'open_booking_id' => $booking->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->cancel($booking));
        $this->other()->commit();

        $this->assertApiError(ErrorCode::CancellationPending, fn () => $this->cancel($booking));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_two_staff_cancels_one_wins(): void
    {
        $booking = $this->booking($this->noura);

        $this->other()->beginTransaction();
        $this->other()->select('select id from service_requests where id = ? for update', [$booking->service_request_id]);
        $this->other()->table('bookings')->where('id', $booking->id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->cancel($booking));
        $this->other()->commit();

        $this->assertApiError(ErrorCode::InvalidState, fn () => $this->cancel($booking));
        $this->assertSame(0, CancellationRequest::query()->where('booking_id', $booking->id)->count());
    }
}

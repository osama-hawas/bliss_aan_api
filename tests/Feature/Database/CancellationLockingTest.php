<?php

namespace Tests\Feature\Database;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\CancellationRequesterRole;
use App\Enums\CancellationStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\Provider;
use App\Models\User;
use App\Services\Bookings\BookingExecution;
use App\Services\Cancellations\BookingCancellations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 6 locking with two real MySQL sessions (slice-6-contract.md §3–§4, §8). The other session plays the first of
 * two racing writers and holds the request row (first in the Slice 6 lock order); this session must wait (1 s lock
 * wait → 1205), then decide on what was committed.
 */
class CancellationLockingTest extends TestCase
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

    private function holdRequest(Booking $booking): void
    {
        $this->other()->beginTransaction();
        $this->other()->select('select id from service_requests where id = ? for update', [$booking->service_request_id]);
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

    private function pending(Booking $booking): CancellationRequest
    {
        return app(BookingCancellations::class)->request($this->client, CancellationRequesterRole::Client, $booking, CancellationReason::Other, 'Plans changed');
    }

    public function test_concurrent_decisions_one_wins_the_other_is_refused(): void
    {
        $booking = $this->booking($this->noura);
        $c = $this->pending($booking);
        $decide = fn () => app(BookingCancellations::class)->decide($this->admin, $c->fresh(), false, 'Second admin', null);

        // The first admin's acceptance, committed by the other session while it holds the request lock.
        $this->holdRequest($booking);
        $this->other()->table('cancellation_requests')->where('id', $c->id)->update(['status' => 'accepted', 'open_booking_id' => null, 'decided_at' => now()]);
        $this->other()->table('bookings')->where('id', $booking->id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->shortLockWait();
        $this->assertWaits($decide);
        $this->other()->commit();

        $this->assertApiError(ErrorCode::InvalidState, $decide);
        $this->assertSame(CancellationStatus::Accepted, $c->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_concurrent_cancellation_requests_one_pending_only(): void
    {
        $booking = $this->booking($this->noura);

        $this->holdRequest($booking);
        $this->other()->table('cancellation_requests')->insert([
            'reference' => 'CN-0000001', 'booking_id' => $booking->id, 'requester_id' => $this->noura->user_id, 'requester_role' => 'provider',
            'reason_code' => 'emergency', 'reason_text' => 'x', 'status' => 'under_review', 'open_booking_id' => $booking->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->pending($booking));
        $this->other()->commit();

        $this->assertApiError(ErrorCode::CancellationPending, fn () => $this->pending($booking));
        $this->assertSame(1, CancellationRequest::query()->count());
    }

    public function test_start_waits_for_a_cancellation_request_then_is_blocked(): void
    {
        $booking = $this->booking($this->noura);
        $this->at(self::EVENT_STARTS);

        $this->holdRequest($booking);
        $this->other()->table('cancellation_requests')->insert([
            'reference' => 'CN-0000002', 'booking_id' => $booking->id, 'requester_id' => $this->client->id, 'requester_role' => 'client',
            'reason_code' => 'other', 'reason_text' => 'x', 'status' => 'under_review', 'open_booking_id' => $booking->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->shortLockWait();
        $start = fn () => app(BookingExecution::class)->start($this->noura->user, $booking);
        $this->assertWaits($start);
        $this->other()->commit();

        $this->assertApiError(ErrorCode::CancellationPending, $start);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_the_unique_guard_refuses_a_second_pending_row(): void
    {
        $booking = $this->booking($this->noura);
        $this->pending($booking);

        $this->expectException(UniqueConstraintViolationException::class);
        CancellationRequest::query()->create([
            'reference' => 'CN-0000003', 'booking_id' => $booking->id, 'requester_id' => $this->client->id, 'requester_role' => 'client',
            'reason_code' => 'other', 'reason_text' => 'x', 'status' => 'under_review', 'open_booking_id' => $booking->id,
        ]);
    }
}

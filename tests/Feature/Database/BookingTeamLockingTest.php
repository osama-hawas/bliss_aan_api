<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\BookingAssignment;
use App\Models\Provider;
use App\Services\Team\BookingTeam;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTeam;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * Slice 13 §4 locking with two real MySQL sessions: setting a booking's team takes the Slice 6 lock order (request →
 * booking), so it waits for a concurrent completion or member change and then decides on what was committed.
 */
class BookingTeamLockingTest extends TestCase
{
    use BuildsTeam, UsesCommittedRows;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
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

    public function test_set_team_waits_for_a_completion_then_is_refused(): void
    {
        $reem = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);
        $set = fn () => app(BookingTeam::class)->set($this->noura->user, Booking::query()->findOrFail($booking->id), [$reem->id]);

        // The completion, committed by the other session while it holds the request row (first in the lock order).
        $this->other()->beginTransaction();
        $this->other()->select('select id from service_requests where id = ? for update', [$booking->service_request_id]);
        $this->other()->table('bookings')->where('id', $booking->id)->update(['status' => 'completed', 'completed_at' => now()]);

        $this->shortLockWait();
        $this->assertWaits($set);
        $this->other()->commit();

        try {
            $set();
            $this->fail('Expected INVALID_STATE.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::InvalidState, $e->errorCode);
        }
        $this->assertSame(0, BookingAssignment::query()->count());
    }

    public function test_set_team_waits_for_a_member_being_disabled_then_refuses_her(): void
    {
        $reem = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);
        $set = fn () => app(BookingTeam::class)->set($this->noura->user, Booking::query()->findOrFail($booking->id), [$reem->id]);

        $this->other()->beginTransaction();
        $this->other()->select('select id from provider_team_members where id = ? for update', [$reem->id]);
        $this->other()->table('provider_team_members')->where('id', $reem->id)->update(['status' => 'disabled', 'disabled_at' => now()]);

        $this->shortLockWait();
        $this->assertWaits($set);
        $this->other()->commit();

        $this->expectException(ValidationException::class);
        $set();
    }
}

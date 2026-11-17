<?php

namespace Tests\Feature\Database;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\StaffTickets;
use App\Services\Support\SupportTickets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * D258 with two real MySQL sessions: a staff status move takes the ticket row lock, so it waits for the user's reply
 * (which holds the row) and then moves from the committed status.
 */
class TicketStatusLockingTest extends TestCase
{
    use UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    public function test_staff_move_waits_for_the_users_reply_and_uses_its_status(): void
    {
        $sara = User::factory()->client()->create();
        $staff = User::factory()->admin()->create();
        [$ticket] = app(SupportTickets::class)->create($sara, (string) Str::uuid(), ['topic' => 'other', 'subject' => 'Help', 'message' => 'First']);
        $ticket->forceFill(['status' => TicketStatus::Waiting])->save();

        // Her reply (waiting → in_progress) in the other session, holding the ticket row.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->table('support_tickets')->where('id', $ticket->id)->lockForUpdate()->first();

        $this->shortLockWait();
        $this->assertWaits(fn () => app(StaffTickets::class)->changeStatus($staff, $ticket->id, TicketStatus::Resolved, null));

        $other->table('support_tickets')->where('id', $ticket->id)->update(['status' => 'in_progress']);
        $other->commit();

        app(StaffTickets::class)->changeStatus($staff, $ticket->id, TicketStatus::Resolved, null);
        $this->assertSame(TicketStatus::Resolved, SupportTicket::query()->find($ticket->id)->status);
        $this->assertSame('in_progress>resolved', DB::table('activity_events')->where('event', 'ticket_status_changed')->get()
            ->map(fn ($e) => $e->from_status.'>'.$e->to_status)->implode(','));
    }
}

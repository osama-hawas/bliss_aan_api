<?php

namespace Tests\Feature\Database;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Support\SupportTickets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * D160 with two real MySQL sessions: a reply holds the ticket row while it moves the status, so a second reply waits
 * and then reads the committed status (one `resolved → open` move, two messages).
 */
class TicketReplyLockingTest extends TestCase
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

    public function test_a_second_reply_waits_for_the_first_and_sees_its_status(): void
    {
        $sara = User::factory()->client()->create();
        [$ticket] = app(SupportTickets::class)->create($sara, (string) Str::uuid(), ['topic' => 'other', 'subject' => 'Help', 'message' => 'First']);
        $ticket->forceFill(['status' => TicketStatus::Resolved])->save();

        // The other session is the first reply: it holds the ticket row.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->table('support_tickets')->where('id', $ticket->id)->lockForUpdate()->first();

        $this->shortLockWait();
        $this->assertWaits(fn () => app(SupportTickets::class)->reply($sara, $ticket->id, ['body' => 'Second', 'client_uuid' => (string) Str::uuid()]));

        // It reopens the ticket and commits; the waiting reply then reads `open` and leaves it open.
        $other->table('ticket_messages')->insert(['ticket_id' => $ticket->id, 'author_id' => $sara->id, 'body' => 'Reply A', 'is_internal' => false, 'created_at' => now(), 'updated_at' => now()]);
        $other->table('support_tickets')->where('id', $ticket->id)->update(['status' => 'open']);
        $other->commit();

        [$message] = app(SupportTickets::class)->reply($sara, $ticket->id, ['body' => 'Reply B', 'client_uuid' => (string) Str::uuid()]);

        $this->assertSame('Reply B', $message->body);
        $this->assertSame(TicketStatus::Open, SupportTicket::query()->find($ticket->id)->status);
        $this->assertSame(3, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame('open>open', DB::table('activity_events')->where('event', 'ticket_replied')->get()->map(fn ($e) => $e->from_status.'>'.$e->to_status)->implode(','));
    }
}

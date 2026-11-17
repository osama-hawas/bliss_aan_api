<?php

namespace Tests\Feature\Support;

use App\Enums\TicketRelatedType;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\ConversationAccessLog;
use App\Models\Provider;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §7 (Req 42, D260): chat review only through an assigned ticket, with a reason, logged per page.
 */
class ChatReviewTest extends TestCase
{
    use BuildsBookings, BuildsNotifications, BuildsOperations, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
        $this->staff = $this->ops();
        app(AppSettings::class)->set('support.chat_access_minutes', 30); // the seeded value (D260)
    }

    private function url(int $ticket, ?int $conversation = null, string $tail = ''): string
    {
        return "/api/v1/admin/support-tickets/{$ticket}/conversations".($conversation ? "/{$conversation}{$tail}" : '');
    }

    public function test_assignee_reviews_with_a_reason_and_every_page_is_logged(): void
    {
        $request = $this->created($this->openBody());
        $mine = $this->conversationFor($request, $this->noura, 5);
        $this->conversationFor($request, $this->huda, 1);
        $t = $this->ticket($this->client, TicketRelatedType::ServiceRequest->value, $request->id);
        $t->forceFill(['assigned_to' => $this->staff->id])->save();

        $this->asStaff($this->staff)->getJson($this->url($t->id))->assertOk()->assertJsonCount(2, 'data'); // her ticket: all of the request's chats

        $first = $this->asStaff($this->staff)->postJson($this->url($t->id, $mine->id, '/review'), ['reason' => 'Late arrival complaint', 'per_page' => 3])
            ->assertOk()
            ->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'Message 5')
            ->assertJsonPath('data.messages.0.sender_side', 'client')
            ->assertJsonPath('data.conversation.id', $mine->id)
            ->assertJsonPath('meta.has_more', true);
        $logId = $first->json('data.access_log_id');
        $before = $first->json('meta.next_before');

        $this->asStaff($this->staff)->getJson($this->url($t->id, $mine->id, '/messages')."?access_log_id={$logId}&before={$before}&per_page=3")
            ->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonPath('meta.has_more', false);

        $logs = ConversationAccessLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('Late arrival complaint', $logs[1]->reason);
        $this->assertSame($before, $logs[1]->before_message_id);
        $this->assertSame([3, 2], $logs->pluck('messages_count')->all());
        $this->assertSame(2, AuditLog::query()->where('action', 'chat.reviewed')->count());
        // Read-only: no read marker moved, the user is not told.
        $this->assertSame(0, DB::table('conversation_reads')->count());
        $this->assertSame([], array_filter($this->events($this->client), fn ($e) => str_contains($e, 'chat')));

        // The ticket detail shows the access log.
        $this->asStaff($this->staff)->getJson("/api/v1/admin/support-tickets/{$t->id}")
            ->assertJsonPath('data.chat.can_review', true)->assertJsonPath('data.chat.conversations_count', 2)
            ->assertJsonCount(2, 'data.chat_access_logs');
    }

    public function test_window_expiry_and_foreign_log(): void
    {
        $request = $this->created($this->openBody());
        $c = $this->conversationFor($request, $this->noura);
        $t = $this->ticket($this->client, TicketRelatedType::ServiceRequest->value, $request->id);
        $t->forceFill(['assigned_to' => $this->staff->id])->save();
        $logId = $this->asStaff($this->staff)->postJson($this->url($t->id, $c->id, '/review'), ['reason' => 'Complaint'])->json('data.access_log_id');

        $this->travel(31)->minutes();
        $this->asStaff($this->staff)->getJson($this->url($t->id, $c->id, '/messages')."?access_log_id={$logId}")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        app(AppSettings::class)->set('support.chat_access_minutes', null);
        $logId = $this->asStaff($this->staff)->postJson($this->url($t->id, $c->id, '/review'), ['reason' => 'Complaint'])->json('data.access_log_id');
        $this->asStaff($this->staff)->getJson($this->url($t->id, $c->id, '/messages')."?access_log_id={$logId}")->assertStatus(409); // null = reason every page
    }

    public function test_only_the_assignee_with_permission_on_an_open_linked_ticket(): void
    {
        $request = $this->created($this->openBody());
        $c = $this->conversationFor($request, $this->noura);
        $t = $this->ticket($this->client, TicketRelatedType::ServiceRequest->value, $request->id);
        $body = ['reason' => 'Complaint'];

        $this->asStaff($this->staff)->postJson($this->url($t->id, $c->id, '/review'), $body)->assertForbidden(); // not assigned
        $t->forceFill(['assigned_to' => $this->staff->id])->save();
        $noChat = $this->staffWith(['support.view', 'support.reply']);
        $t2 = $this->ticket($this->client, TicketRelatedType::ServiceRequest->value, $request->id);
        $t2->forceFill(['assigned_to' => $noChat->id])->save();
        $this->asStaff($noChat)->postJson($this->url($t2->id, $c->id, '/review'), $body)->assertForbidden(); // no chats.view_for_ticket

        $this->asStaff($this->staff)->postJson($this->url($t->id, $c->id, '/review'), ['reason' => ''])->assertStatus(422);
        $t->forceFill(['status' => TicketStatus::Closed])->save();
        $this->asStaff($this->staff)->postJson($this->url($t->id, $c->id, '/review'), $body)->assertStatus(409);
        $this->assertSame(0, ConversationAccessLog::query()->count());
    }

    public function test_only_conversations_of_the_linked_record_where_the_user_takes_part(): void
    {
        $booking = $this->booking($this->noura);
        $request = $booking->serviceRequest;
        $booked = $this->conversationFor($request, $this->noura);
        $other = $this->conversationFor($request, $this->huda);
        $elsewhere = $this->conversationFor($this->created($this->openBody()), $this->noura);

        // A booking ticket: only the booked provider's conversation.
        $t = $this->ticket($this->client, TicketRelatedType::Booking->value, $booking->id);
        $t->forceFill(['assigned_to' => $this->staff->id])->save();
        $this->asStaff($this->staff)->getJson($this->url($t->id))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $booked->id);
        $this->asStaff($this->staff)->postJson($this->url($t->id, $other->id, '/review'), ['reason' => 'x reason'])->assertNotFound();
        $this->asStaff($this->staff)->postJson($this->url($t->id, $elsewhere->id, '/review'), ['reason' => 'x reason'])->assertNotFound();

        // A provider's ticket on the request: only her own conversation.
        $p = $this->ticket($this->huda->user, TicketRelatedType::ServiceRequest->value, $request->id);
        $p->forceFill(['assigned_to' => $this->staff->id])->save();
        $this->asStaff($this->staff)->getJson($this->url($p->id))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);

        // No linked record: nothing.
        $none = $this->ticket($this->client);
        $none->forceFill(['assigned_to' => $this->staff->id])->save();
        $this->asStaff($this->staff)->getJson($this->url($none->id))->assertJsonCount(0, 'data');
        $this->asStaff($this->staff)->postJson($this->url($none->id, $booked->id, '/review'), ['reason' => 'x reason'])->assertNotFound();
    }
}

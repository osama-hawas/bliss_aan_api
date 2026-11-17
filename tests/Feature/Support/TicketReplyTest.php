<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\ActivityEvent;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Support\SupportTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * slice-12b-contract.md §5, D160, D162: her reply, the status it moves, `client_uuid` dedupe.
 */
class TicketReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $sara;

    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sara = User::factory()->client()->create(['name' => 'Sara']);
        $this->ticket = $this->actingAsApi($this->sara)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shared/support-tickets', ['topic' => 'other', 'subject' => 'Help', 'message' => 'First'])
            ->assertCreated()->json('data.id');
    }

    private function reply(array $body = [], ?User $as = null, ?int $ticket = null): TestResponse
    {
        return $this->actingAsApi($as ?? $this->sara)->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/shared/support-tickets/'.($ticket ?? $this->ticket).'/messages', [...['body' => 'More details', 'client_uuid' => (string) Str::uuid()], ...$body]);
    }

    private function setStatus(TicketStatus $status): void
    {
        SupportTicket::query()->whereKey($this->ticket)->update(['status' => $status]);
    }

    public function test_reply_on_open_keeps_it_open(): void
    {
        $this->travel(10)->minutes();
        $this->reply(['body' => '  More details  '])->assertCreated()
            ->assertJsonPath('message', 'Reply sent.')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.body', 'More details')
            ->assertJsonPath('data.messages.1.from_staff', false);

        $this->assertTrue(SupportTicket::query()->find($this->ticket)->last_message_at->isAfter(now()->subMinute()));
    }

    public function test_reply_moves_resolved_to_open_and_waiting_to_in_progress(): void
    {
        $this->setStatus(TicketStatus::Resolved);
        $this->reply()->assertCreated()->assertJsonPath('data.status', 'open');

        $this->setStatus(TicketStatus::Waiting);
        $this->reply()->assertCreated()->assertJsonPath('data.status', 'in_progress');

        $this->setStatus(TicketStatus::InProgress);
        $this->reply()->assertCreated()->assertJsonPath('data.status', 'in_progress');

        $moves = ActivityEvent::query()->where('event', 'ticket_replied')->orderBy('id')->get(['from_status', 'to_status'])
            ->map(fn ($e) => $e->from_status.'>'.$e->to_status)->all();
        $this->assertSame(['resolved>open', 'waiting>in_progress', 'in_progress>in_progress'], $moves);
    }

    public function test_closed_ticket_refuses_replies(): void
    {
        $this->setStatus(TicketStatus::Closed);

        $this->reply()->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($this->sara)->getJson("/api/v1/shared/support-tickets/{$this->ticket}")->assertJsonPath('data.can_reply', false);
        $this->assertSame(1, TicketMessage::query()->count());
    }

    public function test_same_client_uuid_replays_and_a_different_body_conflicts(): void
    {
        $uuid = (string) Str::uuid();
        $this->setStatus(TicketStatus::Resolved);
        $this->reply(['client_uuid' => $uuid])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->setStatus(TicketStatus::Resolved);

        // The replay changes nothing (no second message, no second status move).
        $this->reply(['client_uuid' => $uuid])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->reply(['client_uuid' => $uuid, 'body' => 'Changed'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(2, TicketMessage::query()->count());
    }

    public function test_validation(): void
    {
        $this->reply(['body' => '', 'client_uuid' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['body', 'client_uuid']);
        $this->reply(['body' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors(['body']);
    }

    public function test_foreign_ticket_is_404(): void
    {
        $other = User::factory()->provider()->create();
        $this->reply([], $other)->assertNotFound();
        $this->reply([], null, 999999)->assertNotFound();
        $this->assertSame(1, TicketMessage::query()->count());
    }

    public function test_two_replies_store_two_messages_with_one_status_move(): void
    {
        $this->setStatus(TicketStatus::Resolved);
        $tickets = app(SupportTickets::class);

        [$first, $firstIsNew] = $tickets->reply($this->sara, $this->ticket, ['body' => 'One', 'client_uuid' => (string) Str::uuid()]);
        [$second, $secondIsNew] = $tickets->reply($this->sara, $this->ticket, ['body' => 'Two', 'client_uuid' => (string) Str::uuid()]);

        $this->assertTrue($firstIsNew && $secondIsNew);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(['First', 'One', 'Two'], TicketMessage::query()->orderBy('id')->pluck('body')->all());
        $this->assertSame(TicketStatus::Open, SupportTicket::query()->find($this->ticket)->status);

        $events = ActivityEvent::query()->where('event', 'ticket_replied')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(1, $events->filter(fn ($e) => $e->from_status !== $e->to_status)->count());
        $this->assertSame(['resolved', 'open'], [$events[0]->from_status, $events[0]->to_status]);
        $this->assertSame([$first->id, $second->id], $events->map(fn ($e) => $e->meta['message_id'])->all());
    }
}

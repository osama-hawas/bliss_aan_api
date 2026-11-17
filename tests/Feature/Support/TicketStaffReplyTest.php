<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §6 (D258, D161, E20): staff replies, the status they move, dedupe and what the user sees.
 */
class TicketStaffReplyTest extends TestCase
{
    use BuildsNotifications, BuildsOperations, RefreshDatabase;

    private User $sara;

    private User $staff;

    private SupportTicket $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sara = User::factory()->client()->create(['name' => 'Sara Al Harbi']);
        $this->staff = $this->ops();
        $this->staff->update(['name' => 'Hind Staff']);
        $this->t = $this->ticket($this->sara);
    }

    private function reply(array $body = []): TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$this->t->id}/messages",
            [...['body' => 'We are checking with the provider.', 'client_uuid' => (string) Str::uuid()], ...$body]);
    }

    public function test_reply_moves_open_to_waiting_and_notifies(): void
    {
        $this->reply()->assertCreated()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.messages.1.kind', 'reply')
            ->assertJsonPath('data.messages.1.author.name', 'Hind Staff')
            ->assertJsonPath('data.last_message_from', 'staff');
        $this->assertSame(['support_replied'], $this->events($this->sara));
        $row = $this->row($this->sara, 'support_replied');
        $this->assertEquals(['type' => 'support_ticket', 'id' => $this->t->id], $row->data['target']);

        // She sees the support team, never the staff name (D161); her reply moves waiting → in_progress (D160).
        $this->actingAsApi($this->sara)->withHeader('Accept-Language', 'en')->getJson("/api/v1/shared/support-tickets/{$this->t->id}")
            ->assertJsonPath('data.messages.1.from_staff', true)
            ->assertJsonPath('data.messages.1.author_name', 'Bliss aan support')
            ->assertJsonMissing(['author_name' => 'Hind Staff']);
        $this->actingAsApi($this->sara)->postJson("/api/v1/shared/support-tickets/{$this->t->id}/messages", ['body' => 'Thanks', 'client_uuid' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.status', 'in_progress');
    }

    public function test_reply_keeps_waiting_and_resolved(): void
    {
        $this->t->forceFill(['status' => TicketStatus::Resolved])->save();
        $this->reply()->assertCreated()->assertJsonPath('data.status', 'resolved');
    }

    public function test_reply_on_closed_is_refused(): void
    {
        $this->t->forceFill(['status' => TicketStatus::Closed])->save();
        $this->reply()->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        // An internal note is still allowed.
        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$this->t->id}/notes", ['body' => 'Closing note', 'client_uuid' => (string) Str::uuid()])->assertCreated();
    }

    public function test_client_uuid_replay_and_conflict(): void
    {
        $uuid = (string) Str::uuid();
        $this->reply(['client_uuid' => $uuid])->assertCreated();
        $this->reply(['client_uuid' => $uuid])->assertOk();
        $this->reply(['client_uuid' => $uuid, 'body' => 'Another text'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(2, TicketMessage::query()->where('ticket_id', $this->t->id)->count());
        $this->assertSame(['support_replied'], $this->events($this->sara));
    }

    public function test_validation_and_permission(): void
    {
        $this->reply(['body' => ''])->assertStatus(422);
        $this->reply(['body' => str_repeat('a', 1001)])->assertStatus(422);
        $this->reply(['client_uuid' => 'nope'])->assertStatus(422);
        $this->asStaff($this->staffWith(['support.view']))->postJson("/api/v1/admin/support-tickets/{$this->t->id}/messages",
            ['body' => 'x', 'client_uuid' => (string) Str::uuid()])->assertForbidden();
    }
}

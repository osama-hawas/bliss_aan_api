<?php

namespace Tests\Feature\Support;

use App\Enums\AdminRole;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * slice-17-contract.md §6 (D258, D259): the staff queue, detail, assignment and status moves.
 */
class AdminTicketsTest extends TestCase
{
    use BuildsNotifications, BuildsOperations, RefreshDatabase;

    private User $sara;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sara = User::factory()->client()->create(['name' => 'Sara Al Harbi']);
        $this->staff = $this->ops();
    }

    private function moveTo(SupportTicket $t, string $status, ?string $reason = null, ?User $as = null): TestResponse
    {
        return $this->asStaff($as ?? $this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/status", array_filter(['status' => $status, 'reason' => $reason]));
    }

    public function test_queue_with_filters(): void
    {
        $mine = $this->ticket($this->sara);
        $mine->forceFill(['assigned_to' => $this->staff->id])->save();
        $this->ticket(User::factory()->provider()->create(), status: TicketStatus::Resolved);

        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'reference', 'status', 'status_label', 'topic', 'topic_label', 'subject',
                'user' => ['id', 'type', 'name', 'phone'], 'related', 'assigned_to', 'last_message_at', 'last_message_from', 'created_at']]]);
        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets?filter[assigned]=me')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('data.0.last_message_from', 'user');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets?filter[assigned]=none')->assertJsonCount(1, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets?filter[status][]=resolved')->assertJsonCount(1, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets?filter[user_type]=client')->assertJsonCount(1, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets?q=Sara')->assertJsonCount(1, 'data');
        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets?filter[assigned]=nobody')->assertStatus(422);
    }

    public function test_detail_shows_internal_notes_and_actions(): void
    {
        $t = $this->ticket($this->sara);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/notes", ['body' => 'Check booking', 'client_uuid' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.messages.1.kind', 'note')
            ->assertJsonPath('data.messages.0.kind', 'user')
            ->assertJsonPath('data.status', 'open') // a note moves nothing
            ->assertJsonPath('data.actions.can_take', true)
            ->assertJsonPath('data.actions.transitions', ['in_progress', 'waiting', 'resolved', 'closed'])
            ->assertJsonPath('data.chat.available', false);

        // The user never sees the note (D161).
        $this->actingAsApi($this->sara)->getJson("/api/v1/shared/support-tickets/{$t->id}")->assertOk()->assertJsonCount(1, 'data.messages');
        $this->assertSame([], $this->events($this->sara));
    }

    public function test_assign_and_take(): void
    {
        $t = $this->ticket($this->sara);
        $other = $this->ops();

        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/assign", ['assignee_id' => $other->id])->assertOk()
            ->assertJsonPath('data.assigned_to.id', $other->id);
        $this->assertSame('support_ticket.assigned', AuditLog::query()->latest('id')->value('action'));
        // Taken already → 409; unassign, then take.
        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/take")->assertStatus(409);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/assign", ['assignee_id' => null])->assertOk()->assertJsonPath('data.assigned_to', null);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/take")->assertOk()->assertJsonPath('data.assigned_to.id', $this->staff->id);

        // Only active staff holding support.reply.
        $store = $this->ops(AdminRole::StoreContentOfficer);
        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/assign", ['assignee_id' => $store->id])->assertStatus(422)->assertJsonValidationErrors('assignee_id');
        $this->asStaff($this->staff)->postJson("/api/v1/admin/support-tickets/{$t->id}/assign", ['assignee_id' => $this->sara->id])->assertStatus(422);
        // assign needs support.assign
        $replier = $this->staffWith(['support.view', 'support.reply']);
        $this->asStaff($replier)->postJson("/api/v1/admin/support-tickets/{$t->id}/assign", ['assignee_id' => null])->assertForbidden();
    }

    public function test_status_moves_reasons_and_notifications(): void
    {
        $t = $this->ticket($this->sara);

        $this->moveTo($t, 'in_progress')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->moveTo($t, 'resolved')->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.actions.transitions', ['closed', 'in_progress']);
        $this->assertNotNull($t->refresh()->resolved_at);
        $this->assertSame(['support_ticket_resolved'], $this->events($this->sara));

        $this->moveTo($t, 'in_progress')->assertStatus(422)->assertJsonValidationErrors('reason'); // reopen needs a reason
        $this->moveTo($t, 'in_progress', 'She wrote again by phone')->assertOk();
        $this->assertNull($t->refresh()->resolved_at);
        $this->moveTo($t, 'resolved')->assertOk();
        $this->assertSame(['support_ticket_resolved', 'support_ticket_resolved'], $this->events($this->sara)); // one per resolve

        $this->moveTo($t, 'closed')->assertStatus(422);
        $this->moveTo($t, 'closed', 'Done')->assertOk()->assertJsonPath('data.actions.transitions', [])->assertJsonPath('data.actions.can_reply', false);
        $this->assertNotNull($t->refresh()->closed_at);
        $this->assertCount(2, $this->events($this->sara)); // resolved → closed sends nothing more
        $this->moveTo($t, 'open')->assertStatus(409); // closed is final
    }

    public function test_closing_straight_from_open_notifies_and_closed_is_final(): void
    {
        $t = $this->ticket($this->sara);
        $this->moveTo($t, 'closed', 'Spam')->assertOk();
        $this->assertSame(['support_ticket_closed'], $this->events($this->sara)); // no "reply if…" text on a closed ticket
        $this->moveTo($t, 'in_progress', 'Reopen')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        // The user cannot reply to a closed ticket (D160).
        $this->actingAsApi($this->sara)->postJson("/api/v1/shared/support-tickets/{$t->id}/messages", ['body' => 'Hi', 'client_uuid' => (string) Str::uuid()])->assertStatus(409);
    }

    public function test_access(): void
    {
        $t = $this->ticket($this->sara);
        $viewer = $this->staffWith(['support.view']);

        $this->asStaff($viewer)->getJson("/api/v1/admin/support-tickets/{$t->id}")->assertOk()
            ->assertJsonPath('data.actions.can_reply', false)->assertJsonPath('data.actions.transitions', []);
        $this->moveTo($t, 'resolved', null, $viewer)->assertForbidden();
        $this->asStaff($this->staffWith(['bookings.view']))->getJson('/api/v1/admin/support-tickets')->assertForbidden();
        $this->actingAsApi($this->sara)->getJson('/api/v1/admin/support-tickets')->assertForbidden();
        $this->asStaff($this->staff)->getJson('/api/v1/admin/support-tickets/999999')->assertNotFound();
    }
}

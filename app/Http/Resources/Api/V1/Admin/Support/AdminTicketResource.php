<?php

namespace App\Http\Resources\Api\V1\Admin\Support;

use App\Enums\Permission;
use App\Enums\TicketStatus;
use App\Http\Resources\Concerns\DescribesStaffHistory;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ConversationAccessLog;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Services\Support\ChatReview;
use App\Services\Support\StaffTicketQueries;
use App\Services\Support\StaffTickets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard ticket (`support.view`, slice-17-contract.md §6). Staff see the user's name and phone, the assignee and every
 * message, internal notes included (D161 applies to mobile only). `detailed()` adds the thread, history, chat review
 * availability, the access log and the caller's actions.
 *
 * @mixin SupportTicket
 */
class AdminTicketResource extends JsonResource
{
    use DescribesStaffHistory, FormatsApiValues;

    private bool $full = false;

    public function detailed(): static
    {
        $this->full = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $t = $this->resource;
        $lastAuthor = $t->getAttribute('last_message_author_id');

        $row = [
            'id' => $t->id,
            'reference' => $t->reference,
            'status' => $t->status->value,
            'status_label' => $t->status->label(),
            'topic' => $t->topic->value,
            'topic_label' => $t->topic->label(),
            'subject' => $t->subject,
            'user' => ['id' => $t->user_id, 'type' => $t->user?->type?->value, 'name' => $t->user?->name, 'phone' => $t->user?->phone],
            'related' => $t->related_type ? ['type' => $t->related_type->value, 'id' => $t->related_id, 'reference' => $t->relatedReference] : null,
            'assigned_to' => $t->assigned_to ? ['id' => $t->assigned_to, 'name' => $t->assignee?->name] : null,
            'last_message_at' => $this->dateTime($t->last_message_at),
            'last_message_from' => $lastAuthor === null ? null : ((int) $lastAuthor === (int) $t->user_id ? 'user' : 'staff'),
            'resolved_at' => $this->dateTime($t->resolved_at),
            'closed_at' => $this->dateTime($t->closed_at),
            'created_at' => $this->dateTime($t->created_at),
            'updated_at' => $this->dateTime($t->updated_at),
        ];

        if (! $this->full) {
            return $row;
        }

        $staff = $request->user();
        $canReply = $staff->checkPermissionTo(Permission::SupportReply->value);
        $chat = app(ChatReview::class);

        return [
            ...$row,
            'messages' => $t->messages->map(fn (TicketMessage $m) => [
                'id' => $m->id,
                'kind' => $m->is_internal ? 'note' : ((int) $m->author_id === (int) $t->user_id ? 'user' : 'reply'),
                'author' => ['id' => $m->author_id, 'name' => $m->author?->name],
                'body' => $m->body,
                'created_at' => $this->dateTime($m->created_at),
            ])->values()->all(),
            'history' => $this->staffHistory(app(StaffTicketQueries::class)->history($t)),
            'chat' => [
                'available' => $t->related_type !== null,
                'conversations_count' => $chat->availableCount($t),
                'can_review' => $chat->canReview($staff, $t),
            ],
            'chat_access_logs' => $t->accessLogs->map(fn (ConversationAccessLog $l) => [
                'id' => $l->id,
                'staff' => ['id' => $l->staff_id, 'name' => $l->staff?->name],
                'conversation_id' => $l->conversation_id,
                'reason' => $l->reason,
                'messages_count' => $l->messages_count,
                'before_message_id' => $l->before_message_id,
                'created_at' => $this->dateTime($l->created_at),
            ])->values()->all(),
            'actions' => [
                'can_reply' => $canReply && $t->status->acceptsReplies(),
                'can_note' => $canReply,
                'can_assign' => $staff->checkPermissionTo(Permission::SupportAssign->value),
                'can_take' => $canReply && $t->assigned_to === null,
                'transitions' => $canReply ? array_map(fn (TicketStatus $s) => $s->value, StaffTickets::transitions($t->status)) : [],
            ],
        ];
    }
}

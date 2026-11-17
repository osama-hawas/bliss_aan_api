<?php

namespace App\Services\Support;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\Permission;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\ActivityEvent;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use App\Services\Settings\AppSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff handling of support tickets (slice-17-contract.md §6, D258, D259, D265). Every write locks the ticket row, so a
 * staff move and a user reply (SupportTickets::reply) serialize on it. Staff are never named to the user (D161).
 */
class StaffTickets
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly AppSettings $settings,
    ) {}

    /**
     * Allowed staff moves (D258). `closed` is final.
     *
     * @return list<TicketStatus>
     */
    public static function transitions(TicketStatus $from): array
    {
        return match ($from) {
            TicketStatus::Open => [TicketStatus::InProgress, TicketStatus::Waiting, TicketStatus::Resolved, TicketStatus::Closed],
            TicketStatus::InProgress => [TicketStatus::Waiting, TicketStatus::Resolved, TicketStatus::Closed],
            TicketStatus::Waiting => [TicketStatus::InProgress, TicketStatus::Resolved, TicketStatus::Closed],
            TicketStatus::Resolved => [TicketStatus::Closed, TicketStatus::InProgress],
            TicketStatus::Closed => [],
        };
    }

    /**
     * A reason is required to close or to reopen a resolved ticket (D258).
     */
    public static function needsReason(TicketStatus $from, TicketStatus $to): bool
    {
        return $to === TicketStatus::Closed || ($from === TicketStatus::Resolved && $to === TicketStatus::InProgress);
    }

    /**
     * Assign to an active staff member holding `support.reply`, or unassign with null (`support.assign`, D259).
     */
    public function assign(User $staff, int $ticketId, ?int $assigneeId, ?string $reason): SupportTicket
    {
        $assignee = null;
        if ($assigneeId !== null) {
            $assignee = User::query()->whereKey($assigneeId)->where('type', UserType::Admin)->where('status', UserStatus::Active)->first();
            if (! $assignee || ! $assignee->checkPermissionTo(Permission::SupportReply->value)) {
                throw ValidationException::withMessages(['assignee_id' => [__('support.assignee_invalid')]]);
            }
        }

        return DB::transaction(function () use ($staff, $ticketId, $assignee, $reason) {
            $ticket = $this->lock($ticketId);
            $old = $ticket->assigned_to;
            $ticket->forceFill(['assigned_to' => $assignee?->id])->save();
            $this->activity->record($ticket, 'ticket_assigned', null, null, $staff, ActivityVisibility::Internal,
                ['from' => $old, 'to' => $assignee?->id, 'reason' => $reason]);
            $this->audit->log('support_ticket.assigned', $staff, $ticket, $reason, ['assigned_to' => $old], ['assigned_to' => $assignee?->id]);

            return $ticket;
        });
    }

    /**
     * Take an unassigned ticket (`support.reply`, D259). Reassigning needs `support.assign`.
     */
    public function take(User $staff, int $ticketId): SupportTicket
    {
        return DB::transaction(function () use ($staff, $ticketId) {
            $ticket = $this->lock($ticketId);
            if ($ticket->assigned_to !== null) {
                if ((int) $ticket->assigned_to === $staff->id) {
                    return $ticket; // already hers: idempotent
                }
                throw new ApiException(ErrorCode::InvalidState);
            }
            $ticket->forceFill(['assigned_to' => $staff->id])->save();
            $this->activity->record($ticket, 'ticket_assigned', null, null, $staff, ActivityVisibility::Internal, ['from' => null, 'to' => $staff->id, 'taken' => true]);
            $this->audit->log('support_ticket.assigned', $staff, $ticket, null, ['assigned_to' => null], ['assigned_to' => $staff->id]);

            return $ticket;
        });
    }

    /**
     * A staff reply (`is_internal = false`) or internal note (`is_internal = true`), deduplicated by `client_uuid`
     * per author (12b pattern). A reply is refused on a closed ticket and moves `open` / `in_progress` → `waiting`
     * (D258); a note is allowed in any status and moves nothing.
     *
     * @return array{0: TicketMessage, 1: bool} the message and whether it was created now (false = replay)
     */
    public function write(User $staff, int $ticketId, string $body, string $clientUuid, bool $internal): array
    {
        $body = trim($body);
        $fingerprint = hash('sha256', json_encode([$ticketId, $body, $internal]));
        SupportTicket::query()->whereKey($ticketId)->exists() || throw new ApiException(ErrorCode::NotFound);

        if ($existing = $this->replay($staff, $clientUuid, $fingerprint)) {
            return [$existing, false];
        }

        try {
            $message = DB::transaction(function () use ($staff, $ticketId, $body, $clientUuid, $fingerprint, $internal) {
                $ticket = $this->lock($ticketId);
                if (! $internal && ! $ticket->status->acceptsReplies()) {
                    throw new ApiException(ErrorCode::InvalidState);
                }

                $message = TicketMessage::query()->create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $staff->id,
                    'body' => $body,
                    'is_internal' => $internal,
                    'client_uuid' => $clientUuid,
                    'fingerprint' => $fingerprint,
                ]);

                if ($internal) {
                    $this->audit->log('support_ticket.note_added', $staff, $ticket, null, null, ['message_id' => $message->id]);

                    return $message;
                }

                $from = $ticket->status;
                $to = in_array($from, [TicketStatus::Open, TicketStatus::InProgress], true) ? TicketStatus::Waiting : $from;
                $ticket->forceFill(['status' => $to, 'last_message_at' => now()])->save();
                $this->activity->record($ticket, 'ticket_replied_by_staff', $from, $to, $staff, $this->userSide($ticket), ['message_id' => $message->id]);
                $this->audit->log('support_ticket.replied', $staff, $ticket, null, ['status' => $from->value], ['status' => $to->value, 'message_id' => $message->id]);
                $this->notifier->send((int) $ticket->user_id, NotificationEvent::SupportReplied, ['reference' => $ticket->reference],
                    NotificationTargetType::SupportTicket, $ticket->id, 'support_replied:'.$message->id, $staff); // E20

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return [$this->replay($staff, $clientUuid, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
        }

        return [$message, true];
    }

    /**
     * A staff status move (D258). E21 on `resolved`, and on `closed` straight from an open state.
     */
    public function changeStatus(User $staff, int $ticketId, TicketStatus $to, ?string $reason): SupportTicket
    {
        return DB::transaction(function () use ($staff, $ticketId, $to, $reason) {
            $ticket = $this->lock($ticketId);
            $from = $ticket->status;
            if (! in_array($to, self::transitions($from), true)) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if (self::needsReason($from, $to) && ($reason === null || trim($reason) === '')) {
                throw ValidationException::withMessages(['reason' => [__('support.reason_required')]]);
            }

            $this->move($ticket, $to);
            $event = $this->activity->record($ticket, 'ticket_status_changed', $from, $to, $staff, $this->userSide($ticket));
            $this->audit->log('support_ticket.status_changed', $staff, $ticket, $reason, ['status' => $from->value], ['status' => $to->value]);
            $this->notifyStatus($ticket, $from, $to, $event, $staff);

            return $ticket;
        });
    }

    /**
     * Closes resolved tickets older than `support.auto_close_days` (D265). Null or not a positive number = off.
     */
    public function autoClose(): int
    {
        $days = $this->settings->get('support.auto_close_days');
        if (! is_numeric($days) || (int) $days <= 0) {
            return 0;
        }

        $closed = 0;
        SupportTicket::query()->where('status', TicketStatus::Resolved)->where('resolved_at', '<=', now()->subDays((int) $days))
            ->orderBy('id')->pluck('id')
            ->each(function (int $id) use (&$closed) {
                DB::transaction(function () use ($id, &$closed) {
                    $ticket = SupportTicket::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $ticket || $ticket->status !== TicketStatus::Resolved) {
                        return; // the user replied meanwhile (resolved → open)
                    }
                    $this->move($ticket, TicketStatus::Closed);
                    $this->activity->record($ticket, 'ticket_auto_closed', TicketStatus::Resolved, TicketStatus::Closed, null, $this->userSide($ticket));
                    $closed++;
                });
            });

        return $closed;
    }

    private function move(SupportTicket $ticket, TicketStatus $to): void
    {
        $ticket->forceFill([
            'status' => $to,
            'resolved_at' => $to === TicketStatus::Resolved ? now() : ($to === TicketStatus::Closed ? $ticket->resolved_at : null),
            'closed_at' => $to === TicketStatus::Closed ? now() : null,
        ])->save();
    }

    private function notifyStatus(SupportTicket $ticket, TicketStatus $from, TicketStatus $to, ActivityEvent $event, User $staff): void
    {
        $notify = $to === TicketStatus::Resolved
            || ($to === TicketStatus::Closed && in_array($from, [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Waiting], true));
        if ($notify) {
            $this->notifier->send((int) $ticket->user_id, NotificationEvent::SupportTicketResolved, ['reference' => $ticket->reference],
                NotificationTargetType::SupportTicket, $ticket->id, 'ticket_status:'.$ticket->id.':'.$event->id, $staff); // E21
        }
    }

    private function lock(int $ticketId): SupportTicket
    {
        return SupportTicket::query()->whereKey($ticketId)->lockForUpdate()->first() ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * History entries the ticket's user may see are on her side (12b pattern); internal ones stay staff-only.
     */
    private function userSide(SupportTicket $ticket): ActivityVisibility
    {
        $type = $ticket->relationLoaded('user') ? $ticket->user?->type : User::query()->whereKey($ticket->user_id)->value('type');
        $type = $type instanceof UserType ? $type : UserType::tryFrom((string) $type);

        return $type === UserType::Provider ? ActivityVisibility::Provider : ActivityVisibility::Client;
    }

    private function replay(User $staff, string $uuid, string $fingerprint): ?TicketMessage
    {
        $existing = TicketMessage::query()->where('author_id', $staff->id)->where('client_uuid', $uuid)->first();
        if ($existing && ! hash_equals((string) $existing->fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }
}

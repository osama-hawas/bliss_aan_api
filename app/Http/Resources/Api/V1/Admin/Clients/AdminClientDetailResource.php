<?php

namespace App\Http\Resources\Api\V1\Admin\Clients;

use App\Models\AccountStatusLog;
use App\Models\ActivityEvent;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Accounts\ClientAccounts;
use Illuminate\Http\Request;

/**
 * Client detail: her latest requests (reference and status only), open items, open sessions and status history.
 * Deletion requests belong to Slice 12 (Q76): `deletion_request` stays null here.
 *
 * @mixin User
 */
class AdminClientDetailResource extends AdminClientResource
{
    public function toArray(Request $request): array
    {
        $revoked = ActivityEvent::query()->whereMorphedTo('subject', $this->resource)->where('event', 'account_suspended')
            ->orderBy('id')->get(['meta'])->map(fn (ActivityEvent $e) => $e->meta['sessions_revoked'] ?? 0)->values();
        $suspensions = 0;

        $logs = AccountStatusLog::query()->where('user_id', $this->id)->with('actor')->orderBy('id')->get()
            ->map(function (AccountStatusLog $log) use ($revoked, &$suspensions) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'action_label' => __('accounts.actions.'.$log->action),
                    'reason' => $log->reason,
                    'internal_note' => $log->internal_note,
                    'actor' => $log->actor?->name,
                    'sessions_revoked' => $log->action === 'client_suspended' ? (int) ($revoked[$suspensions++] ?? 0) : null,
                    'created_at' => $this->dateTime($log->created_at),
                ];
            })->reverse()->values();

        return [
            ...parent::toArray($request),
            'open_items' => app(ClientAccounts::class)->openItems($this->resource),
            'active_sessions' => $this->devices()->whereNull('revoked_at')->count(),
            'linked' => ServiceRequest::query()->where('client_id', $this->id)->with('booking')->latest('id')->limit(20)->get()
                ->map(fn (ServiceRequest $r) => [
                    'id' => $r->id,
                    'kind' => $r->booking ? 'booking' : 'request',
                    'reference' => $r->reference,
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                    'booking_status' => $r->booking?->status->value,
                    'href' => '/requests/'.$r->id,
                    'created_at' => $this->dateTime($r->created_at),
                ])->values(),
            'status_logs' => $logs,
            'deletion_request' => null,
        ];
    }
}

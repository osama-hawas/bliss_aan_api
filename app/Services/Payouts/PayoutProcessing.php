<?php

namespace App\Services\Payouts;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\LedgerEntryType;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\PayoutRequestStatus;
use App\Exceptions\ApiException;
use App\Models\PayoutRequest;
use App\Models\Provider;
use App\Models\ProviderLedgerEntry;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Manual staff processing (slice-11-contract.md §6, D216): mark paid with a bank transfer reference, or reject with a
 * reason (the money returns to her balance). Repeating the same action is a no-op; every action is audit-logged.
 */
class PayoutProcessing
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DeadlockRetry $retry,
    ) {}

    public function markPaid(User $staff, PayoutRequest $request, string $transferReference, ?string $note): PayoutRequest
    {
        $transferReference = trim($transferReference);

        return $this->locked($request, 'payout.mark_paid', function (PayoutRequest $locked, Provider $provider) use ($staff, $transferReference, $note) {
            if ($locked->status === PayoutRequestStatus::Paid && $locked->transfer_reference === $transferReference) {
                return $locked; // idempotent repeat
            }
            if ($locked->status !== PayoutRequestStatus::Requested) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if (PayoutRequest::query()->where('paid_transfer_reference', $transferReference)->exists()) {
                throw $this->duplicateReference();
            }

            try {
                $locked->update([
                    'status' => PayoutRequestStatus::Paid,
                    'transfer_reference' => $transferReference,
                    'paid_transfer_reference' => $transferReference,
                    'internal_note' => $note ?: $locked->internal_note,
                    'processed_by' => $staff->id,
                    'processed_at' => now(),
                    'open_provider_id' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->duplicateReference();
            }

            $this->history($locked, 'payout_paid', PayoutRequestStatus::Paid, $staff, $note);
            $this->audit->log('payout_paid', $staff, $locked, null,
                ['status' => PayoutRequestStatus::Requested->value],
                ['status' => PayoutRequestStatus::Paid->value, 'amount_cents' => $locked->amount_cents, 'transfer_reference' => $transferReference]);
            $this->notifier->send((int) $provider->user_id, NotificationEvent::PayoutPaid, ['reference' => $locked->reference],
                NotificationTargetType::PayoutRequest, $locked->id, 'payout_paid:'.$locked->id, $staff); // E18

            return $locked;
        });
    }

    public function reject(User $staff, PayoutRequest $request, string $reason, ?string $note): PayoutRequest
    {
        $reason = trim($reason);

        return $this->locked($request, 'payout.reject', function (PayoutRequest $locked, Provider $provider) use ($staff, $reason, $note) {
            if ($locked->status === PayoutRequestStatus::Rejected && $locked->rejection_reason === $reason) {
                return $locked; // idempotent repeat
            }
            if ($locked->status !== PayoutRequestStatus::Requested) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $locked->update([
                'status' => PayoutRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'internal_note' => $note ?: $locked->internal_note,
                'processed_by' => $staff->id,
                'processed_at' => now(),
                'open_provider_id' => null,
            ]);
            // The debited amount returns to her available balance (append-only, contract §4.4).
            ProviderLedgerEntry::query()->firstOrCreate(['entry_key' => 'payout_reversal:'.$locked->id], [
                'provider_id' => $locked->provider_id,
                'type' => LedgerEntryType::PayoutReversal,
                'amount_cents' => $locked->amount_cents,
                'payout_request_id' => $locked->id,
                'available_at' => now(),
            ]);

            $this->history($locked, 'payout_rejected', PayoutRequestStatus::Rejected, $staff, $note, ['reason' => $reason]);
            $this->audit->log('payout_rejected', $staff, $locked, $reason,
                ['status' => PayoutRequestStatus::Requested->value],
                ['status' => PayoutRequestStatus::Rejected->value, 'amount_cents' => $locked->amount_cents]);
            $this->notifier->send((int) $provider->user_id, NotificationEvent::PayoutRejected, ['reference' => $locked->reference],
                NotificationTargetType::PayoutRequest, $locked->id, 'payout_rejected:'.$locked->id, $staff); // E19

            return $locked;
        });
    }

    /**
     * The full IBAN of the snapshot, for the transfer (D223). Every call is audit-logged.
     */
    public function revealIban(User $staff, PayoutRequest $request): string
    {
        $this->audit->log('payout_iban_viewed', $staff, $request);

        return $request->iban;
    }

    /**
     * Lock order = creation's: provider row, then the payout request row.
     */
    private function locked(PayoutRequest $request, string $operation, Closure $work): PayoutRequest
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($request, $work) {
            $provider = Provider::query()->whereKey($request->provider_id)->lockForUpdate()->firstOrFail();
            $locked = PayoutRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            return $work($locked, $provider);
        }), $operation, ['payout_request_id' => $request->id]);
    }

    private function history(PayoutRequest $request, string $event, PayoutRequestStatus $to, User $staff, ?string $note, array $meta = []): void
    {
        $this->activity->record($request, $event, PayoutRequestStatus::Requested, $to, $staff, ActivityVisibility::All, $meta);
        if ($note) {
            $this->activity->record($request, 'payout_note', null, null, $staff, ActivityVisibility::Internal, ['note' => $note]);
        }
    }

    private function duplicateReference(): ApiException
    {
        return new ApiException(ErrorCode::ValidationFailed, errors: [
            'transfer_reference' => [__('payouts.transfer_reference_used')],
        ]);
    }
}

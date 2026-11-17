<?php

namespace App\Services\Finance;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\LedgerAdjustmentDirection;
use App\Enums\LedgerAdjustmentReason;
use App\Enums\LedgerAdjustmentStatus;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\LedgerAdjustment;
use App\Models\Provider;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\DeadlockRetry;
use App\Services\Notifications\Notifier;
use App\Services\ServiceRequests\IdempotencyRace;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Staff adjustments of a provider's ledger (slice-19-contract.md §4, D286–D293).
 *
 * Lock order: requester `users` row (create only) → adjustment row (decide only) → `providers` row. S11 locks
 * payout request → providers, so there is no cycle. Every write runs in `DeadlockRetry`.
 */
class LedgerAdjustments
{
    private const MAX_REFERENCE_ATTEMPTS = 5;

    private const REFERENCE_INDEX = 'ledger_adjustments_reference_unique';

    private const IDEMPOTENCY_INDEX = 'ledger_adjustments_idempotency_unique';

    private const PAYOUT_RETURN_INDEX = 'ledger_adjustments_payout_return_key_unique';

    public function __construct(
        private readonly AdjustmentLimits $limits,
        private readonly ProviderLedgerWriter $ledger,
        private readonly AdjustmentReferenceGenerator $references,
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DeadlockRetry $deadlocks,
    ) {}

    /**
     * @param  array{provider_id: int, direction: string, amount: string, reason_code: string, reason: string, booking_id?: ?int, payout_request_id?: ?int}  $data
     * @return array{0: LedgerAdjustment, 1: bool} the adjustment and whether it was created now (false = replay)
     */
    public function create(User $staff, string $key, array $data): array
    {
        $data = $this->normalize($data);
        $fingerprint = hash('sha256', json_encode($data));

        if ($existing = $this->replay($staff, $key, $fingerprint)) {
            return [$existing, false];
        }

        $max = $this->limits->requiredMaxCents();
        $amount = $data['amount_cents'];
        if ($amount > $max) {
            throw new ApiException(ErrorCode::AdjustmentLimitExceeded, null, [
                'amount' => [__('finance.amount_over_max', ['max' => Money::fromCents($max)])],
            ]);
        }
        $direction = LedgerAdjustmentDirection::from($data['direction']);
        $reason = LedgerAdjustmentReason::from($data['reason_code']);
        if (($only = $reason->onlyDirection()) && $only !== $direction) {
            throw ValidationException::withMessages(['direction' => [__('finance.direction_not_allowed')]]);
        }
        $requiresApproval = $this->limits->requiresApproval($amount);
        $snapshot = $this->limits->snapshot();

        try {
            return $this->deadlocks->run(fn () => DB::transaction(function () use ($staff, $key, $fingerprint, $data, $direction, $reason, $amount, $requiresApproval, $snapshot) {
                User::query()->whereKey($staff->id)->lockForUpdate()->first();
                if ($existing = $this->replay($staff, $key, $fingerprint)) {
                    return [$existing, false];
                }

                $provider = Provider::query()->whereKey($data['provider_id'])->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(['provider_id' => [__('validation.exists', ['attribute' => 'provider_id'])]]);
                $this->checkLinks($provider, $reason, $amount, $data['booking_id'], $data['payout_request_id']);

                $adjustment = $this->insert([
                    'provider_id' => $provider->id,
                    'direction' => $direction,
                    'amount_cents' => $amount,
                    'reason_code' => $reason,
                    'reason' => $data['reason'],
                    'booking_id' => $data['booking_id'],
                    'payout_request_id' => $data['payout_request_id'],
                    'payout_return_key' => $reason === LedgerAdjustmentReason::PayoutReturned ? $data['payout_request_id'] : null,
                    'status' => LedgerAdjustmentStatus::PendingApproval,
                    'requires_approval' => $requiresApproval,
                    'limits' => $snapshot,
                    'requested_by' => $staff->id,
                    'idempotency_key' => $key,
                    'idempotency_fingerprint' => $fingerprint,
                ]);

                $this->audit->log('ledger_adjustment_requested', $staff, $adjustment, $data['reason'], null, $this->auditValues($adjustment));
                $this->activity->record($adjustment, 'ledger_adjustment_requested', null, LedgerAdjustmentStatus::PendingApproval, $staff, ActivityVisibility::Internal, ['requires_approval' => $requiresApproval]);

                if (! $requiresApproval) {
                    $this->post($adjustment, $provider, $staff, null);
                }

                return [$adjustment, true];
            }), 'ledger_adjustment.create', ['staff_id' => $staff->id, 'provider_id' => $data['provider_id']]);
        } catch (IdempotencyRace) {
            return [$this->replay($staff, $key, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
        }
    }

    /**
     * A second staff member approves and posts (D289). Already posted → unchanged.
     */
    public function approve(User $staff, int $id): LedgerAdjustment
    {
        return $this->deadlocks->run(fn () => DB::transaction(function () use ($staff, $id) {
            $adjustment = $this->lockAdjustment($id);
            if ($adjustment->status === LedgerAdjustmentStatus::Posted) {
                return $adjustment;
            }
            if ($adjustment->status !== LedgerAdjustmentStatus::PendingApproval) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if ($adjustment->requested_by === $staff->id) {
                throw new ApiException(ErrorCode::SelfApproval);
            }

            $provider = Provider::query()->whereKey($adjustment->provider_id)->lockForUpdate()->firstOrFail();
            $this->checkLinks($provider, $adjustment->reason_code, $adjustment->amount_cents, $adjustment->booking_id, $adjustment->payout_request_id, $adjustment->id);

            $this->post($adjustment, $provider, $staff, $staff);
            $this->audit->log('ledger_adjustment_approved', $staff, $adjustment, null,
                ['status' => LedgerAdjustmentStatus::PendingApproval->value], $this->auditValues($adjustment));

            return $adjustment;
        }), 'ledger_adjustment.approve', ['staff_id' => $staff->id, 'adjustment_id' => $id]);
    }

    /**
     * Reject a pending request; the requester rejecting her own request cancels it (D289).
     */
    public function reject(User $staff, int $id, string $reason): LedgerAdjustment
    {
        $reason = trim($reason);

        return $this->deadlocks->run(fn () => DB::transaction(function () use ($staff, $id, $reason) {
            $adjustment = $this->lockAdjustment($id);
            if (in_array($adjustment->status, [LedgerAdjustmentStatus::Rejected, LedgerAdjustmentStatus::Cancelled], true)) {
                return $adjustment;
            }
            if ($adjustment->status !== LedgerAdjustmentStatus::PendingApproval) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $to = $adjustment->requested_by === $staff->id ? LedgerAdjustmentStatus::Cancelled : LedgerAdjustmentStatus::Rejected;
            $adjustment->forceFill([
                'status' => $to,
                'decided_by' => $staff->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
                'payout_return_key' => null,
            ])->save();

            $action = $to === LedgerAdjustmentStatus::Cancelled ? 'ledger_adjustment_cancelled' : 'ledger_adjustment_rejected';
            $this->audit->log($action, $staff, $adjustment, $reason, ['status' => LedgerAdjustmentStatus::PendingApproval->value], ['status' => $to->value]);
            $this->activity->record($adjustment, $action, LedgerAdjustmentStatus::PendingApproval, $to, $staff, ActivityVisibility::Internal);

            return $adjustment;
        }), 'ledger_adjustment.reject', ['staff_id' => $staff->id, 'adjustment_id' => $id]);
    }

    /**
     * Inside the caller's transaction, under the providers row lock: ledger row + `posted` + history + notification.
     */
    private function post(LedgerAdjustment $adjustment, Provider $provider, User $actor, ?User $approver): void
    {
        $now = now();
        $adjustment->forceFill([
            'status' => LedgerAdjustmentStatus::Posted,
            'decided_by' => $approver?->id,
            'decided_at' => $approver ? $now : null,
            'posted_at' => $now,
            'ledger_entry_key' => $adjustment->entryKey(),
        ])->save();

        $this->ledger->post($adjustment);

        if ($approver === null) {
            $this->audit->log('ledger_adjustment_posted', $actor, $adjustment, null, null, $this->auditValues($adjustment));
        }
        $this->activity->record($adjustment, 'ledger_adjustment_posted', LedgerAdjustmentStatus::PendingApproval, LedgerAdjustmentStatus::Posted, $actor, ActivityVisibility::Internal);

        $event = $adjustment->direction === LedgerAdjustmentDirection::Credit ? NotificationEvent::LedgerCreditPosted : NotificationEvent::LedgerDebitPosted;
        $this->notifier->send(
            $provider->user_id,
            $event,
            ['reference' => $adjustment->reference, 'amount' => Money::fromCents($adjustment->amount_cents), 'currency' => config('app.currency')],
            NotificationTargetType::Earnings,
            null,
            $event->value.':'.$adjustment->id,
            $actor,
        );
    }

    /**
     * §4.3 link rules. `$ignoreId` = the adjustment being approved (its own payout return key).
     */
    private function checkLinks(Provider $provider, LedgerAdjustmentReason $reason, int $amount, ?int $bookingId, ?int $payoutId, ?int $ignoreId = null): void
    {
        if ($reason === LedgerAdjustmentReason::BookingCorrection && $bookingId === null) {
            throw ValidationException::withMessages(['booking_id' => [__('finance.booking_required')]]);
        }
        if ($bookingId !== null && ! Booking::query()->whereKey($bookingId)->where('provider_id', $provider->id)->exists()) {
            throw ValidationException::withMessages(['booking_id' => [__('finance.booking_not_hers')]]);
        }

        if ($reason !== LedgerAdjustmentReason::PayoutReturned) {
            if ($payoutId !== null && ! $this->payoutOf($provider, $payoutId)) {
                throw ValidationException::withMessages(['payout_request_id' => [__('finance.payout_not_hers')]]);
            }

            return;
        }

        if ($payoutId === null) {
            throw ValidationException::withMessages(['payout_request_id' => [__('finance.payout_required')]]);
        }
        $payout = $this->payoutOf($provider, $payoutId);
        if (! $payout) {
            throw ValidationException::withMessages(['payout_request_id' => [__('finance.payout_not_hers')]]);
        }
        if ($payout->status !== 'paid') {
            throw ValidationException::withMessages(['payout_request_id' => [__('finance.payout_not_paid')]]);
        }
        if ($amount > (int) $payout->amount_cents) {
            throw ValidationException::withMessages(['amount' => [__('finance.amount_over_payout', ['amount' => Money::fromCents((int) $payout->amount_cents)])]]);
        }
        $taken = LedgerAdjustment::query()->where('payout_return_key', $payoutId)
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($taken) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }
    }

    /**
     * Her payout request (S11 table `payout_requests`, read through the query builder).
     */
    private function payoutOf(Provider $provider, int $payoutId): ?object
    {
        return DB::table('payout_requests')->where('id', $payoutId)->where('provider_id', $provider->id)
            ->first(['id', 'status', 'amount_cents']);
    }

    private function lockAdjustment(int $id): LedgerAdjustment
    {
        return LedgerAdjustment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{provider_id: int, direction: string, amount_cents: int, reason_code: string, reason: string, booking_id: ?int, payout_request_id: ?int}
     */
    private function normalize(array $data): array
    {
        return [
            'provider_id' => (int) $data['provider_id'],
            'direction' => (string) $data['direction'],
            'amount_cents' => Money::toCents(Money::normalize((string) $data['amount'])),
            'reason_code' => (string) $data['reason_code'],
            'reason' => trim((string) $data['reason']),
            'booking_id' => isset($data['booking_id']) ? (int) $data['booking_id'] : null,
            'payout_request_id' => isset($data['payout_request_id']) ? (int) $data['payout_request_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(LedgerAdjustment $a): array
    {
        return [
            'reference' => $a->reference,
            'provider_id' => $a->provider_id,
            'direction' => $a->direction->value,
            'amount' => Money::fromCents($a->amount_cents),
            'reason_code' => $a->reason_code->value,
            'status' => $a->status->value,
        ];
    }

    private function replay(User $staff, string $key, string $fingerprint): ?LedgerAdjustment
    {
        $existing = LedgerAdjustment::query()->where('requested_by', $staff->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): LedgerAdjustment
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return LedgerAdjustment::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                    throw new IdempotencyRace;
                }
                if (str_contains($e->getMessage(), self::PAYOUT_RETURN_INDEX)) {
                    throw new ApiException(ErrorCode::DuplicateRequest);
                }
                if (! str_contains($e->getMessage(), self::REFERENCE_INDEX)) {
                    throw $e;
                }
            }
        }

        Log::error('Ledger adjustment reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }
}

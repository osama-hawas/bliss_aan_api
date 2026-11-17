<?php

namespace App\Services\Payouts;

use App\Enums\LedgerEntryType;
use App\Models\Booking;
use App\Models\ProviderLedgerEntry;
use App\Services\Availability\DeadlockRetry;
use App\Services\Bookings\BookingLocks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A refund settled after the booking's earning exists (slice-11-contract.md §4.2, D220): one negative
 * `refund_adjustment` = refund minus its share of the earning's commission. Idempotent on `refund:{id}`.
 */
class RefundAdjustments
{
    public function __construct(
        private readonly S10Payments $payments,
        private readonly BookingLocks $locks,
        private readonly DeadlockRetry $retry,
    ) {}

    public function forRefund(int $refundId): ?ProviderLedgerEntry
    {
        $refund = $this->payments->refund($refundId);
        if (! $refund || $refund->status !== S10Payments::REFUND_REFUNDED) {
            return null;
        }
        $booking = Booking::query()->where('payment_id', $refund->payment_id)->first();
        if (! $booking) {
            return null; // a refund on an attempt that never became a booking's payment (S10 §6.4, D185)
        }

        return $this->retry->run(fn () => DB::transaction(function () use ($booking, $refund) {
            $this->locks->lock($booking);
            $this->payments->lockPayment((int) $refund->payment_id);

            $earning = ProviderLedgerEntry::query()->where('entry_key', 'earning:'.$booking->id)->first();
            if (! $earning || in_array((int) $refund->id, $earning->meta['netted_refund_ids'] ?? [], true)) {
                return null; // no earning yet (the earning will net it) or already netted in the earning
            }
            $key = 'refund:'.$refund->id;
            if ($existing = ProviderLedgerEntry::query()->where('entry_key', $key)->first()) {
                return $existing;
            }

            $refundCents = Cents::fromDecimal((string) $refund->amount);
            $commission = Cents::share($refundCents, (int) $earning->commission_bps);

            try {
                return ProviderLedgerEntry::query()->create([
                    'provider_id' => $earning->provider_id,
                    'type' => LedgerEntryType::RefundAdjustment,
                    'amount_cents' => -($refundCents - $commission),
                    'booking_id' => $booking->id,
                    'gross_cents' => -$refundCents,
                    'commission_cents' => -$commission,
                    'commission_bps' => $earning->commission_bps,
                    'available_at' => $earning->available_at,
                    'entry_key' => $key,
                    'meta' => ['refund_id' => (int) $refund->id, 'payment_id' => (int) $refund->payment_id],
                ]);
            } catch (UniqueConstraintViolationException) {
                return ProviderLedgerEntry::query()->where('entry_key', $key)->first();
            }
        }), 'payouts.refund_adjustment', ['refund_id' => $refund->id]);
    }

    /**
     * Safety net for the back-fill command: settled booking refunds without an adjustment.
     */
    public function recordMissing(): int
    {
        $written = 0;
        foreach ($this->payments->settledBookingRefundIds() as $refundId) {
            $written += $this->forRefund((int) $refundId)?->wasRecentlyCreated ? 1 : 0;
        }

        return $written;
    }
}

<?php

namespace App\Services\Payouts;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place Slice 11 reads Slice 10 payment data (slice-11-contract.md §10). Reads rows, never S10 events'
 * payloads, and never writes S10 tables. Values are the S10 contract's (`slice-10-contract.md` §4.1, §5).
 */
class S10Payments
{
    /** `BookingPaymentStatus` values that mean the booking was paid (S10 D190). */
    public const PAID_BOOKING_STATUSES = ['paid', 'partially_refunded'];

    public const PAYMENT_SUCCEEDED = 'succeeded';

    public const REFUND_REFUNDED = 'refunded';

    /**
     * The booking's payment row, locked (lock order request → booking → payment → refunds, S10 §9).
     */
    public function lockPayment(int $paymentId): ?object
    {
        return DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first(['id', 'amount', 'status']);
    }

    /**
     * Settled refunds of a payment (`refunds.status = refunded`), locked. `payments.refunded_amount` is not used: it
     * also counts in-progress reservations.
     *
     * @return Collection<int, object{id: int, amount: string}>
     */
    public function lockSettledRefunds(int $paymentId): Collection
    {
        return DB::table('refunds')->where('payment_id', $paymentId)->where('status', self::REFUND_REFUNDED)
            ->orderBy('id')->lockForUpdate()->get(['id', 'amount']);
    }

    public function refund(int $refundId): ?object
    {
        return DB::table('refunds')->where('id', $refundId)->first(['id', 'payment_id', 'amount', 'status']);
    }

    /**
     * Settled refunds the back-fill must still look at (D220): on a booking payment whose booking already has an
     * `earning`, not netted into that earning (`meta.netted_refund_ids`) and without a `refund:{id}` adjustment yet.
     * Netted and already-adjusted refunds drop out, so the set does not grow with history.
     *
     * @return Collection<int, int> refund ids
     */
    public function settledBookingRefundIds(): Collection
    {
        return DB::table('refunds')
            ->join('bookings', 'bookings.payment_id', '=', 'refunds.payment_id')
            ->join('provider_ledger_entries as earning', fn ($join) => $join->on('earning.booking_id', '=', 'bookings.id')
                ->where('earning.type', '=', 'earning'))
            ->where('refunds.status', self::REFUND_REFUNDED)
            ->whereRaw("NOT JSON_CONTAINS(COALESCE(JSON_EXTRACT(earning.meta, '$.netted_refund_ids'), JSON_ARRAY()), CAST(refunds.id AS CHAR))")
            ->whereNotExists(fn ($q) => $q->from('provider_ledger_entries as adjusted')
                ->whereRaw("adjusted.entry_key = CONCAT('refund:', refunds.id)"))
            ->orderBy('refunds.id')
            ->pluck('refunds.id');
    }
}

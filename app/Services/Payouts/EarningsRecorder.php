<?php

namespace App\Services\Payouts;

use App\Enums\BookingStatus;
use App\Enums\LedgerEntryType;
use App\Models\Booking;
use App\Models\ProviderLedgerEntry;
use App\Services\Availability\DeadlockRetry;
use App\Services\Bookings\BookingLocks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One `earning` entry per paid and completed booking (slice-11-contract.md §4.1, D215, D219). Never throws for a
 * booking that cannot earn (not paid, not completed, settings not specified): it records nothing, and the
 * `payouts:record-earnings` command back-fills once the settings exist.
 */
class EarningsRecorder
{
    public function __construct(
        private readonly PayoutSettings $settings,
        private readonly S10Payments $payments,
        private readonly BookingLocks $locks,
        private readonly DeadlockRetry $retry,
    ) {}

    /**
     * Inside a transaction that already holds the booking's request → booking locks (BookingExecution::complete).
     */
    public function recordLocked(Booking $booking): ?ProviderLedgerEntry
    {
        if ($booking->status !== BookingStatus::Completed || $booking->completed_at === null || $booking->payment_id === null) {
            return null;
        }
        $bps = $this->settings->commissionBps();
        $holdDays = $this->settings->holdDays();
        if ($bps === null || $holdDays === null) {
            return null;
        }
        if (! in_array((string) $booking->getAttributes()['payment_status'], S10Payments::PAID_BOOKING_STATUSES, true)) {
            return null;
        }

        $key = 'earning:'.$booking->id;
        if ($existing = ProviderLedgerEntry::query()->where('entry_key', $key)->first()) {
            return $existing;
        }

        $payment = $this->payments->lockPayment((int) $booking->payment_id);
        if (! $payment || $payment->status !== S10Payments::PAYMENT_SUCCEEDED) {
            return null;
        }
        $refunds = $this->payments->lockSettledRefunds((int) $payment->id);
        $gross = Cents::fromDecimal((string) $payment->amount)
            - $refunds->sum(fn (object $refund) => Cents::fromDecimal((string) $refund->amount));
        if ($gross <= 0) {
            return null;
        }
        $commission = Cents::share($gross, $bps);

        try {
            return ProviderLedgerEntry::query()->create([
                'provider_id' => $booking->provider_id,
                'type' => LedgerEntryType::Earning,
                'amount_cents' => $gross - $commission,
                'booking_id' => $booking->id,
                'gross_cents' => $gross,
                'commission_cents' => $commission,
                'commission_bps' => $bps,
                'available_at' => $booking->completed_at->copy()->addDays($holdDays),
                'entry_key' => $key,
                'meta' => ['payment_id' => (int) $payment->id, 'netted_refund_ids' => $refunds->pluck('id')->map(fn ($id) => (int) $id)->all()],
            ]);
        } catch (UniqueConstraintViolationException) {
            return ProviderLedgerEntry::query()->where('entry_key', $key)->first();
        }
    }

    /**
     * Its own transaction under the booking locks (back-fill).
     */
    public function record(Booking $booking): ?ProviderLedgerEntry
    {
        return $this->retry->run(fn () => DB::transaction(function () use ($booking) {
            [, $locked] = $this->locks->lock($booking);

            return $this->recordLocked($locked);
        }), 'payouts.record_earning', ['booking_id' => $booking->id]);
    }

    /**
     * Paid, completed bookings without an earning (bookings completed while the settings were not specified).
     *
     * @return int entries written
     */
    public function recordMissing(): int
    {
        if (! $this->settings->configured()) {
            return 0;
        }

        $written = 0;
        Booking::query()
            ->where('status', BookingStatus::Completed)
            ->whereNotNull('payment_id')
            ->whereIn('payment_status', S10Payments::PAID_BOOKING_STATUSES)
            ->whereNotExists(fn ($q) => $q->from('provider_ledger_entries')
                ->whereColumn('provider_ledger_entries.booking_id', 'bookings.id')
                ->where('provider_ledger_entries.type', LedgerEntryType::Earning->value))
            ->chunkById(100, function ($bookings) use (&$written) {
                foreach ($bookings as $booking) {
                    $written += $this->record($booking)?->wasRecentlyCreated ? 1 : 0;
                }
            });

        return $written;
    }
}

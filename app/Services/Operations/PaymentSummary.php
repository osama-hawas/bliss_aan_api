<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\CancellationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only payment/refund summary for the operations screens (slice-17-contract.md §5.1, §5.3). Reads Slice 10's
 * `payments` / `refunds` rows (slice-10-contract.md §5) through the query builder, so this slice depends only on the
 * S10 schema, never on its services. Before S10's tables exist (or with no payment on the booking) it returns null /
 * empty: the dashboard then hides the refund action (D113/D263).
 */
class PaymentSummary
{
    private ?bool $available = null;

    /**
     * @return array{id: int, reference: string, status: string, amount: array, refunded_amount: array, refundable_amount: array, refund: string, can_refund: bool}|null
     */
    public function forBooking(Booking $booking): ?array
    {
        $paymentId = $booking->getAttribute('payment_id');
        if (! $paymentId || ! $this->available()) {
            return null;
        }

        $payment = DB::table('payments')->where('id', $paymentId)->first(['id', 'reference', 'status', 'amount', 'currency', 'refunded_amount']);
        if (! $payment) {
            return null;
        }

        $refunds = DB::table('refunds')->where('payment_id', $payment->id)->get(['status', 'amount']);
        $refunded = (int) $refunds->where('status', 'refunded')->sum(fn ($r) => $this->cents($r->amount));
        $amount = $this->cents($payment->amount);
        $reserved = $this->cents($payment->refunded_amount);
        $remainder = max(0, $amount - $reserved);

        $state = match (true) {
            $refunds->contains('status', 'in_progress') => 'in_progress',
            $refunded > 0 && $refunded >= $amount => 'full',
            $refunded > 0 => 'partial',
            default => 'none',
        };

        return [
            'id' => (int) $payment->id,
            'reference' => $payment->reference,
            'status' => $payment->status,
            'amount' => $this->money($amount, $payment->currency),
            'refunded_amount' => $this->money($refunded, $payment->currency),
            'refundable_amount' => $this->money($remainder, $payment->currency),
            'refund' => $state,
            'can_refund' => $payment->status === 'succeeded' && $remainder > 0,
        ];
    }

    /**
     * Refunds linked to one cancellation request (S10 `refunds.cancellation_request_id`).
     *
     * @return list<array{id: int, reference: string, amount: array, status: string}>
     */
    public function refundsForCancellation(CancellationRequest $cancellation): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('refunds')->where('cancellation_request_id', $cancellation->id)->orderBy('id')
            ->get(['id', 'reference', 'amount', 'status'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'reference' => $r->reference, 'amount' => $this->money($this->cents($r->amount), 'SAR'), 'status' => $r->status])
            ->values()->all();
    }

    private function available(): bool
    {
        return $this->available ??= Schema::hasTable('payments') && Schema::hasTable('refunds');
    }

    private function cents(string|int|float|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function money(int $cents, ?string $currency): array
    {
        return ['amount' => number_format($cents / 100, 2, '.', ''), 'currency' => $currency ?: 'SAR'];
    }
}

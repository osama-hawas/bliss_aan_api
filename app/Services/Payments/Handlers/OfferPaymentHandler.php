<?php

namespace App\Services\Payments\Handlers;

use App\Enums\BookingPaymentStatus;
use App\Enums\ErrorCode;
use App\Enums\PayableType;
use App\Enums\PaymentPurpose;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Bookings\BookingLocks;
use App\Services\Bookings\OfferAcceptor;
use App\Services\Payments\PayableHandler;
use App\Services\Payments\PaymentQuote;
use App\Services\Payments\PaymentSettings;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A booking offer as a payable (D185, slice-10-contract.md §4.3): pay the offer total first, then OfferAcceptor runs
 * with the key `payment:{id}` (idempotent replay). An acceptance refusal is an ApiException ⇒ automatic full refund.
 */
class OfferPaymentHandler implements PayableHandler
{
    public function __construct(
        private readonly ServiceRequestQueries $queries,
        private readonly OfferAcceptor $acceptor,
        private readonly BookingLocks $locks,
        private readonly PaymentSettings $settings,
    ) {}

    public function type(): PayableType
    {
        return PayableType::Offer;
    }

    public function findForPayer(User $payer, int $id): Model
    {
        return $this->queries->clientOffer($payer, $id);
    }

    public function quote(User $payer, Model $payable): PaymentQuote
    {
        /** @var Offer $payable */
        if (! $this->settings->bookingPaymentRequired()) {
            throw new ApiException(ErrorCode::PaymentNotRequired);
        }
        $this->acceptor->precheck($payer, $payable);

        return new PaymentQuote(Money::normalize($payable->total), PaymentPurpose::Full, 'Offer '.$payable->reference,
            ['offer_reference' => $payable->reference]);
    }

    public function fulfil(Payment $payment): ?Model
    {
        try {
            $offer = Offer::query()->findOrFail($payment->payable_id);
            [$booking] = $this->acceptor->accept($payment->user()->firstOrFail(), $offer, 'payment:'.$payment->id, $payment);
        } catch (ModelNotFoundException) {
            throw new ApiException(ErrorCode::NotFound);
        }

        return $booking;
    }

    public function lockForRefund(Payment $payment): void
    {
        $booking = Booking::query()->where('payment_id', $payment->id)->first();
        if ($booking) {
            $this->locks->lock($booking);
        }
    }

    public function onRefunded(Payment $payment, Refund $refund): void
    {
        $booking = Booking::query()->where('payment_id', $payment->id)->first();
        if (! $booking) {
            return;
        }
        $settled = $payment->settledRefundCents();
        $booking->update(['payment_status' => $settled >= $payment->amountCents()
            ? BookingPaymentStatus::Refunded
            : BookingPaymentStatus::PartiallyRefunded]);
    }
}

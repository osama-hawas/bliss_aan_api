<?php

namespace App\Services\Payments;

use App\Enums\PayableType;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One per payable type (slice-10-contract.md §4.3, D190). Registered in PayableRegistry from AppServiceProvider.
 */
interface PayableHandler
{
    public function type(): PayableType;

    /**
     * The payer's own payable, or 404 (scoped lookup, never leaks existence).
     *
     * @throws ApiException
     */
    public function findForPayer(User $payer, int $id): Model;

    /**
     * Amount and pre-check. Throw ApiException when it cannot be paid now (nothing is charged).
     *
     * @throws ApiException
     */
    public function quote(User $payer, Model $payable): PaymentQuote;

    /**
     * After a successful payment, outside the payment transaction. Must be idempotent on $payment->id (it can run
     * again after a crash). Throw ApiException ⇒ automatic full refund (D185). Returns the created/confirmed model.
     *
     * @throws ApiException
     */
    public function fulfil(Payment $payment): ?Model;

    /**
     * Lock the payable's rows a refund must hold before the payment row (lock order, §9). Inside a transaction.
     */
    public function lockForRefund(Payment $payment): void;

    /**
     * Inside the transaction that settles a refund: update the payable's own payment status.
     */
    public function onRefunded(Payment $payment, Refund $refund): void;
}

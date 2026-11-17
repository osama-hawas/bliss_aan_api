<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Read-only finance consistency checks (Slice 19, D296, slice-19-contract.md §5). Nothing is fixed automatically.
 */
enum ReconciliationCheck: string
{
    use HasLabel;

    case StalePayments = 'stale_payments';
    case FulfilmentPending = 'fulfilment_pending';
    case FulfilmentFailedUnrefunded = 'fulfilment_failed_unrefunded';
    case RefundsInProgress = 'refunds_in_progress';
    case RefundsFailed = 'refunds_failed';
    case PaymentRefundMismatch = 'payment_refund_mismatch';
    case WebhooksUnprocessed = 'webhooks_unprocessed';
    case MissingEarnings = 'missing_earnings';
    case RefundNotInLedger = 'refund_not_in_ledger';
    case NegativeBalances = 'negative_balances';
    case PendingAdjustments = 'pending_adjustments';

    public function description(): string
    {
        return __('finance.checks.'.$this->value);
    }
}

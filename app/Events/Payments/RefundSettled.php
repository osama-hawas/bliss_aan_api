<?php

namespace App\Events\Payments;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Slice 10 (slice-10-contract.md §4.4): A refund became refunded or failed (`status`). Dispatched after commit. Read money from the rows, not from here.
 */
final class RefundSettled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $refundId, public int $paymentId, public string $status) {}
}

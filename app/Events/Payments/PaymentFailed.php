<?php

namespace App\Events\Payments;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Slice 10 (slice-10-contract.md §4.4): A payment became failed or cancelled. Dispatched after commit. Read money from the rows, not from here.
 */
final class PaymentFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $paymentId) {}
}

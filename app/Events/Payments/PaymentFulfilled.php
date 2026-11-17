<?php

namespace App\Events\Payments;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Slice 10 (slice-10-contract.md §4.4): The payable was delivered after a successful payment (booking created, order confirmed). Dispatched after commit. Read money from the rows, not from here.
 */
final class PaymentFulfilled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $paymentId, public string $payableType, public ?int $fulfilledId) {}
}

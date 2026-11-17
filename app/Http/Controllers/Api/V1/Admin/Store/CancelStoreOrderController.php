<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\ReasonRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminStoreOrderResource;
use App\Services\Store\StoreMoney;
use App\Services\Store\StoreOrderLifecycle;
use App\Services\Store\StoreOrderQueries;
use App\Traits\ApiResponse;

/**
 * Staff cancel (orders.update_status): restores stock; the refund is done on the Slice 10 payment (`meta.refundable`).
 */
class CancelStoreOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoreOrderLifecycle $lifecycle, private readonly StoreOrderQueries $queries) {}

    public function __invoke(ReasonRequest $request, int $order)
    {
        $this->lifecycle->cancelByStaff($this->queries->adminOrder($order), $request->user(), $request->validated('reason'));
        $fresh = $this->queries->adminOrder($order);
        $payment = $fresh->payment;
        $refundable = $payment === null ? null : StoreMoney::cents((string) $payment->amount) - StoreMoney::cents((string) ($payment->refunded_amount ?? '0'));

        return $this->success(new AdminStoreOrderResource($fresh), null, 200, [
            'refundable' => $refundable === null ? null : ['amount' => StoreMoney::amount(max(0, $refundable)), 'currency' => config('app.currency')],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin\Payments;

use App\Enums\RefundKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payments\StoreRefundRequest;
use App\Http\Resources\Api\V1\Admin\Payments\AdminRefundResource;
use App\Models\Payment;
use App\Services\Payments\PaymentRefunds;
use App\Traits\ApiResponse;

/**
 * Staff refund, full or partial, with a reason (D187, refunds.process). 201 new, 200 replay.
 */
class StoreRefundController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentRefunds $refunds) {}

    public function __invoke(StoreRefundRequest $request, int $payment)
    {
        $model = Payment::query()->findOrFail($payment);
        $cancellation = $request->validated('cancellation_request_id');
        [$refund, $isNew] = $this->refunds->refund($model, (string) $request->validated('amount'), RefundKind::Staff, $request->user(),
            $request->validated('reason'), $request->validated('idempotency_key'), $cancellation !== null ? (int) $cancellation : null);

        return $this->success(new AdminRefundResource($refund->fresh(['payment', 'initiator', 'cancellationRequest'])), __('payments.refund_started'), $isNew ? 201 : 200);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reports\ReceiptRequest;
use App\Services\Reports\Receipts\PaymentReceipts;
use App\Services\Reports\Receipts\ReceiptPdf;
use App\Traits\ApiResponse;

/**
 * `GET admin/payments/{id}/receipt` (`finance.view`): the payment receipt PDF (D324, D325). `?lang=` overrides Accept-Language.
 */
class PaymentReceiptController extends Controller
{
    use ApiResponse;

    public function __invoke(ReceiptRequest $request, PaymentReceipts $receipts, ReceiptPdf $pdf, int $payment)
    {
        if ($request->validated('lang')) {
            app()->setLocale($request->validated('lang'));
        }

        return $pdf->download($receipts->issue($payment, $request->user()));
    }
}

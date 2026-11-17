<?php

namespace App\Services\Reports\Receipts;

use App\Models\PaymentReceipt;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Renders a receipt as PDF (D324): dompdf (barryvdh/laravel-dompdf) with Arabic letters shaped and ordered by
 * khaled.alshamaa/ar-php, since dompdf has no Arabic shaping of its own. Font: DejaVu Sans (bundled, Arabic glyphs).
 */
class ReceiptPdf
{
    public function __construct(private readonly PaymentReceipts $receipts) {}

    public function download(PaymentReceipt $receipt): Response
    {
        $rtl = app()->getLocale() === 'ar';
        $arabic = new Arabic;

        // Shape + visually order Arabic text; Latin digits are kept (no Hindi numerals).
        $text = fn (?string $value): string => $value === null || $value === ''
            ? ''
            : (preg_match('/\p{Arabic}/u', $value) ? $arabic->utf8Glyphs($value, 1000, false) : $value);

        $html = view('reports.receipt', [...$this->receipts->document($receipt), 'rtl' => $rtl, 't' => $text])->render();

        return Pdf::loadHTML($html)
            ->setPaper(config('reports.receipt_paper', 'a4'))
            ->setOption('defaultFont', 'DejaVu Sans')
            ->download($receipt->number().'.pdf');
    }
}

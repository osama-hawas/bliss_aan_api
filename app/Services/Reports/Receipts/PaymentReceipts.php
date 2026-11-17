<?php

namespace App\Services\Reports\Receipts;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Payment receipts (D324, D325). Reads S10's `payments`/`refunds` and S9b's `store_orders` through the query builder
 * (no class of those slices, D318). A receipt is issued once — on `PaymentSucceeded` or at the first download — under
 * the payment row lock, with the VAT snapshot it keeps for every reprint.
 */
class PaymentReceipts
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * The receipt of a succeeded payment, issuing it on first use. 404 when the payment (or the payments table) does
     * not exist; 409 INVALID_STATE when the payment has not succeeded.
     */
    public function issue(int $paymentId, ?User $staff = null): PaymentReceipt
    {
        if (! Schema::hasTable('payments')) {
            throw new NotFoundHttpException;
        }

        $existing = PaymentReceipt::query()->where('payment_id', $paymentId)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($paymentId, $staff) {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if ($payment === null) {
                throw new NotFoundHttpException;
            }
            if ($payment->status !== 'succeeded') {
                throw new ApiException(ErrorCode::InvalidState);
            }

            // Re-read under the lock: a concurrent issue may have won.
            $existing = PaymentReceipt::query()->where('payment_id', $paymentId)->first();
            if ($existing !== null) {
                return $existing;
            }

            [$percent, $vat] = $this->vat($payment);
            $payer = DB::table('users')->where('id', $payment->user_id)->first(['name', 'phone']);

            return PaymentReceipt::query()->create([
                // S22 (D338): the parties' names are kept on the financial document, never read live again.
                'payer_name' => $payer?->name,
                'payer_phone' => $payer?->phone,
                'provider_name' => $this->providerName($payment->id),
                'payment_id' => $payment->id,
                'amount' => Money::normalize((string) $payment->amount),
                'currency' => $payment->currency,
                'vat_percent' => $percent,
                'vat_amount' => $vat,
                'issued_by' => $staff?->id,
                'issued_at' => now(),
            ]);
        });
    }

    private function providerName(int $paymentId): ?string
    {
        return Schema::hasColumn('bookings', 'payment_id')
            ? DB::table('bookings')->join('providers', 'providers.id', '=', 'bookings.provider_id')
                ->where('bookings.payment_id', $paymentId)->value('providers.display_name')
            : null;
    }

    /**
     * [percent, amount] included in the payment (OD8: prices include VAT). A store order keeps its own snapshot (S9b);
     * a booking takes `tax.vat_percent` now. Null = no VAT line.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function vat(object $payment): array
    {
        if ($payment->payable_type === 'store_order' && Schema::hasTable('store_orders')) {
            $order = DB::table('store_orders')->where('id', $payment->payable_id)->first(['vat_percent', 'vat_amount']);

            return $order && $order->vat_percent !== null ? [(string) $order->vat_percent, (string) $order->vat_amount] : [null, null];
        }

        $percent = $this->settings->get('tax.vat_percent');
        if ($percent === null || ! is_numeric($percent) || (float) $percent <= 0) {
            return [null, null];
        }

        $basisPoints = self::cents($percent);
        $cents = self::cents($payment->amount);
        $vatCents = intdiv($cents * $basisPoints * 2 + (10000 + $basisPoints), 2 * (10000 + $basisPoints)); // half-up

        return [number_format($basisPoints / 100, 2, '.', ''), sprintf('%d.%02d', intdiv($vatCents, 100), $vatCents % 100)];
    }

    /**
     * Everything the receipt template shows, in the current locale.
     *
     * @return array<string, mixed>
     */
    public function document(PaymentReceipt $receipt): array
    {
        $payment = DB::table('payments')->where('id', $receipt->payment_id)->first();
        // S22 (D338): the snapshot taken at issue; the live row only for receipts issued before it existed.
        $payer = $receipt->payer_name !== null
            ? (object) ['name' => $receipt->payer_name, 'phone' => $receipt->payer_phone]
            : DB::table('users')->where('id', $payment->user_id)->first(['name', 'phone']);
        $card = $payment->card ? json_decode($payment->card, true) : null;
        $locale = app()->getLocale();
        $translate = fn (?string $json) => $json === null ? null : (($values = json_decode($json, true)) && is_array($values) ? ($values[$locale] ?? reset($values)) : $json);

        $document = [
            'receipt' => $receipt,
            'number' => $receipt->number(),
            'payment' => $payment,
            'payer' => $payer,
            'method' => $payment->method === 'apple_pay'
                ? __('reports.receipt.apple_pay')
                : (isset($card['last4']) ? trim(ucfirst((string) ($card['brand'] ?? '')).' · '.__('reports.receipt.card', ['last4' => $card['last4']])) : $payment->method),
            'kind' => $payment->payable_type === 'store_order' ? 'order' : 'booking',
            'booking' => null,
            'order' => null,
            'lines' => [],
            'refunds' => [],
            'refunded' => '0.00',
            'net' => Money::normalize((string) $receipt->amount),
        ];

        if ($document['kind'] === 'order' && Schema::hasTable('store_orders')) {
            $document['order'] = DB::table('store_orders')->where('id', $payment->payable_id)->first();
            $document['lines'] = DB::table('store_order_items')->where('store_order_id', $payment->payable_id)->orderBy('id')->get()
                ->map(fn (object $item) => [
                    'name' => trim($translate($item->name).' '.implode(' / ', array_map(fn ($option) => is_array($option) ? ($option[$locale] ?? reset($option)) : $option, json_decode($item->options ?? '[]', true) ?: []))),
                    'quantity' => (int) $item->quantity,
                    'unit_price' => Money::normalize((string) $item->unit_price),
                    'line_total' => Money::normalize((string) $item->line_total),
                ])->all();
        } elseif (Schema::hasColumn('bookings', 'payment_id')) {
            $booking = DB::table('bookings')
                ->leftJoin('services', 'services.id', '=', 'bookings.service_id')
                ->leftJoin('providers', 'providers.id', '=', 'bookings.provider_id')
                ->where('bookings.payment_id', $payment->id)
                ->first(['bookings.reference', 'bookings.title', 'bookings.starts_at', 'services.name as service_name', 'providers.display_name as provider_name']);
            if ($booking !== null) {
                $booking->service_name = $translate($booking->service_name);
                $booking->provider_name = $receipt->provider_name ?? $booking->provider_name; // S22 (D338)
                $document['booking'] = $booking;
                $document['lines'] = [[
                    'name' => $booking->service_name ?? $booking->title,
                    'quantity' => 1,
                    'unit_price' => Money::normalize((string) $receipt->amount),
                    'line_total' => Money::normalize((string) $receipt->amount),
                ]];
            }
        }

        if (Schema::hasTable('refunds')) {
            $refunds = DB::table('refunds')->where('payment_id', $payment->id)->where('status', 'refunded')->orderBy('id')->get(['reference', 'amount', 'processed_at']);
            $refundedCents = 0;
            foreach ($refunds as $refund) {
                $refundedCents += self::cents($refund->amount);
                $document['refunds'][] = ['reference' => $refund->reference, 'amount' => Money::normalize((string) $refund->amount), 'processed_at' => $refund->processed_at];
            }
            $paidCents = self::cents($receipt->amount);
            $document['refunded'] = sprintf('%d.%02d', intdiv($refundedCents, 100), $refundedCents % 100);
            $net = $paidCents - $refundedCents;
            $document['net'] = ($net < 0 ? '-' : '').sprintf('%d.%02d', intdiv(abs($net), 100), abs($net) % 100);
        }

        return $document;
    }

    /** Integer cents of a decimal(…, 2) value, without floats. */
    private static function cents(mixed $amount): int
    {
        return (int) str_replace('.', '', Money::normalize((string) $amount));
    }
}

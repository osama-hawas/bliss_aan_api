<?php

namespace App\Console\Commands;

use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\PaymentIntents;
use App\Services\Payments\PaymentRefunds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Slice 10 (slice-10-contract.md §6.3): open attempts past their lifetime are cancelled (D191); other open ones are
 * asked again; successes whose fulfilment is still pending are retried; refunds still in progress are re-sent or asked.
 * Nothing is reserved by an open attempt, so expiry frees nothing.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Settle open payments, expire unpaid attempts, retry pending fulfilments and refunds';

    private const QUIET_SECONDS = 60;

    public function handle(PaymentIntents $intents, PaymentRefunds $refunds): int
    {
        $counts = ['expired' => 0, 'refreshed' => 0, 'fulfilment' => 0, 'refunds' => 0];
        $quiet = now()->subSeconds(self::QUIET_SECONDS);

        $open = Payment::query()->whereIn('status', PaymentStatus::open())->orderBy('id')->limit(500)->get();
        foreach ($open as $payment) {
            $this->safely(function () use ($payment, $intents, $quiet, &$counts) {
                if ($payment->expires_at !== null && $payment->expires_at->lte(now())) {
                    $intents->expire($payment);
                    $counts['expired']++;
                } elseif ($payment->last_checked_at === null || $payment->last_checked_at->lte($quiet)) {
                    $intents->refresh($payment, true);
                    $counts['refreshed']++;
                }
            }, $payment->id);
        }

        $pending = Payment::query()->where('status', PaymentStatus::Succeeded)
            ->whereIn('fulfilment_status', [FulfilmentStatus::Pending, FulfilmentStatus::NotApplicable])
            ->where('paid_at', '<=', $quiet)->orderBy('id')->limit(200)->get();
        foreach ($pending as $payment) {
            $this->safely(function () use ($payment, $intents, &$counts) {
                $intents->settle($payment);
                $counts['fulfilment']++;
            }, $payment->id);
        }

        $inProgress = Refund::query()->where('status', RefundStatus::InProgress)->where('updated_at', '<=', $quiet)->orderBy('id')->limit(200)->get();
        foreach ($inProgress as $refund) {
            $this->safely(function () use ($refund, $refunds, &$counts) {
                $refunds->send($refund);
                $counts['refunds']++;
            }, $refund->payment_id);
        }

        $this->info(sprintf('Expired %d, refreshed %d, settled %d, refunds %d.', ...array_values($counts)));

        return self::SUCCESS;
    }

    private function safely(callable $work, int $paymentId): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('payments:reconcile step failed.', ['payment_id' => $paymentId, 'exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}

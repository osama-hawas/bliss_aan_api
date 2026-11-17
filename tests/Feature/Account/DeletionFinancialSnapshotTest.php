<?php

namespace Tests\Feature\Account;

use App\Enums\DeletionReason;
use App\Enums\StoreOrderStatus;
use App\Enums\UserStatus;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Account\AccountDeletion;
use App\Services\Account\AccountDeletionProcessor;
use App\Services\Reports\Receipts\PaymentReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFinance;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * S22 (D338, owner's "middle solution"): the account is anonymised, but her name stays in the financial records
 * (receipt and store order snapshots) and nothing financial is rewritten.
 */
class DeletionFinancialSnapshotTest extends TestCase
{
    use BuildsFinance, BuildsStoreOrders, RefreshDatabase {
        BuildsFinance::staffWith insteadof BuildsStoreOrders;
    }

    private function deleteAccount(User $user): void
    {
        $request = app(AccountDeletion::class)->request($user, DeletionReason::Privacy, null);
        app(AccountDeletionProcessor::class)->process($request->id, null, null);
        $this->assertSame(UserStatus::Deleted, $user->refresh()->status);
    }

    public function test_a_payment_without_a_receipt_gets_one_with_her_name_before_anonymisation(): void
    {
        $client = User::factory()->client()->create(['name' => 'Sara Client', 'phone' => '+966511112222']);
        $paymentId = $this->payment($client, 'succeeded', '500.00');
        $this->payment($client, 'failed', '500.00'); // no receipt for a failed payment

        $this->deleteAccount($client);

        $receipt = PaymentReceipt::query()->where('payment_id', $paymentId)->firstOrFail();
        $this->assertSame('Sara Client', $receipt->payer_name);
        $this->assertSame('+966511112222', $receipt->payer_phone);
        $this->assertSame(1, PaymentReceipt::query()->count());

        $document = app(PaymentReceipts::class)->document($receipt);
        $this->assertSame('Sara Client', $document['payer']->name);
        $this->assertSame('+966511112222', $document['payer']->phone);

        // The PDF still renders for staff.
        $this->actingAsApi($this->financeStaff())->get("/api/v1/admin/payments/{$paymentId}/receipt?lang=en")->assertOk();
    }

    public function test_an_existing_receipt_keeps_its_snapshot_and_is_not_reissued(): void
    {
        $client = User::factory()->client()->create(['name' => 'Noor Payer']);
        $paymentId = $this->payment($client, 'succeeded', '200.00');
        $issued = app(PaymentReceipts::class)->issue($paymentId);
        $this->assertSame('Noor Payer', $issued->payer_name);

        $this->deleteAccount($client);

        $receipt = PaymentReceipt::query()->where('payment_id', $paymentId)->sole();
        $this->assertSame($issued->id, $receipt->id);
        $this->assertSame('Noor Payer', $receipt->payer_name);
    }

    public function test_store_order_recipient_snapshot_and_money_rows_are_untouched(): void
    {
        $client = User::factory()->client()->create(['name' => 'Huda Buyer']);
        $order = $this->bareOrder($client, StoreOrderStatus::Delivered);
        $order->forceFill(['recipient_name' => 'Huda Buyer', 'recipient_phone' => '+966533334444'])->save();
        $paymentId = $this->payment($client, 'succeeded', '70.00', ['payable_type' => 'store_order', 'payable_id' => $order->id, 'purpose' => 'store']);
        $this->refund($paymentId, 'refunded', '10.00');

        $this->deleteAccount($client);

        $order->refresh();
        $this->assertSame('Huda Buyer', $order->recipient_name);
        $this->assertSame('+966533334444', $order->recipient_phone);
        $this->assertSame($client->id, $order->user_id);
        $this->assertSame('Huda Buyer', PaymentReceipt::query()->where('payment_id', $paymentId)->value('payer_name'));
        $this->assertSame('Deleted user', $client->name); // the account itself is anonymised
    }
}

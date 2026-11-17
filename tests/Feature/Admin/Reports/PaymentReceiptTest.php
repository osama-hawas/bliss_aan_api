<?php

namespace Tests\Feature\Admin\Reports;

use App\Enums\AdminRole;
use App\Events\Payments\PaymentSucceeded;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Reports\Receipts\PaymentReceipts;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFinance;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 21 (S21): payment receipt PDFs (D324, D325) over S10's payments (rows inserted by table contract).
 */
class PaymentReceiptTest extends TestCase
{
    use BuildsFinance, BuildsStoreOrders, RefreshDatabase {
        BuildsFinance::staffWith insteadof BuildsStoreOrders;
    }

    private function vat(?int $percent): void
    {
        app(AppSettings::class)->set('tax.vat_percent', $percent);
    }

    private function paid(string $amount = '1150.00', array $extra = []): int
    {
        return $this->payment(User::factory()->client()->create(['name' => 'Sara Client']), 'succeeded', $amount, ['card' => json_encode(['brand' => 'mada', 'last4' => '4242']), ...$extra]);
    }

    public function test_receipt_pdf_is_issued_once_with_the_vat_snapshot(): void
    {
        $this->vat(15);
        $id = $this->paid();
        $admin = $this->financeStaff();

        $response = $this->actingAsApi($admin)->get("/api/v1/admin/payments/{$id}/receipt?lang=ar");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $receipt = PaymentReceipt::query()->where('payment_id', $id)->firstOrFail();
        $this->assertSame('15.00', (string) $receipt->vat_percent);
        $this->assertSame('150.00', (string) $receipt->vat_amount); // 1150 × 15/115
        $this->assertSame($admin->id, $receipt->issued_by);
        $this->assertStringContainsString($receipt->number(), $response->headers->get('content-disposition'));

        // A later setting change never rewrites it.
        $this->vat(null);
        $this->actingAsApi($admin)->get("/api/v1/admin/payments/{$id}/receipt?lang=en")->assertOk();
        $this->assertSame(1, PaymentReceipt::query()->where('payment_id', $id)->count());
        $this->assertSame('150.00', (string) PaymentReceipt::query()->where('payment_id', $id)->value('vat_amount'));
    }

    public function test_no_vat_line_while_vat_is_not_set(): void
    {
        $this->vat(null);
        $receipt = app(PaymentReceipts::class)->issue($this->paid());

        $this->assertNull($receipt->vat_percent);
        $this->assertNull($receipt->vat_amount);
        $this->assertNull($receipt->issued_by);
    }

    public function test_a_store_order_receipt_copies_the_order_vat_snapshot_and_lines(): void
    {
        $this->configureStore();
        $this->vat(5); // ignored: the order keeps its own snapshot
        $order = $this->confirmedOrder();
        $order->forceFill(['vat_percent' => '15.00', 'vat_amount' => '9.13', 'total' => '70.00'])->save();
        $id = $this->paid('70.00', ['payable_type' => 'store_order', 'payable_id' => $order->id, 'purpose' => 'store']);

        $receipts = app(PaymentReceipts::class);
        $receipt = $receipts->issue($id);
        $this->assertSame('15.00', (string) $receipt->vat_percent);
        $this->assertSame('9.13', (string) $receipt->vat_amount);

        $document = $receipts->document($receipt);
        $this->assertSame('order', $document['kind']);
        $this->assertSame($order->reference, $document['order']->reference);
        $this->assertCount(1, $document['lines']);
    }

    public function test_refunds_and_the_net_amount_are_on_the_receipt(): void
    {
        $id = $this->paid('1000.00');
        $this->refund($id, 'refunded', '250.00');
        $this->refund($id, 'refunded', '100.50');
        $this->refund($id, 'failed', '50.00');

        $receipts = app(PaymentReceipts::class);
        $document = $receipts->document($receipts->issue($id));

        $this->assertCount(2, $document['refunds']);
        $this->assertSame('350.50', $document['refunded']);
        $this->assertSame('649.50', $document['net']);
        $this->assertStringContainsString('4242', $document['method']);
    }

    public function test_the_payment_succeeded_event_issues_the_receipt(): void
    {
        $this->vat(15);
        $id = $this->paid('230.00');

        PaymentSucceeded::dispatch($id);

        $receipt = PaymentReceipt::query()->where('payment_id', $id)->firstOrFail();
        $this->assertNull($receipt->issued_by);
        $this->assertSame('30.00', (string) $receipt->vat_amount);

        // A failure in the listener never surfaces (pending payment: no receipt, no exception).
        $pending = $this->payment(User::factory()->client()->create(), 'pending', '10.00');
        PaymentSucceeded::dispatch($pending);
        $this->assertFalse(PaymentReceipt::query()->where('payment_id', $pending)->exists());
    }

    public function test_only_succeeded_payments_have_receipts_and_finance_view_is_required(): void
    {
        $admin = $this->financeStaff();
        $client = User::factory()->client()->create();

        $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/'.$this->payment($client, 'pending', '10.00').'/receipt')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/999999/receipt')->assertNotFound();
        $this->actingAsApi($this->member(AdminRole::OperationsSupport))->getJson('/api/v1/admin/payments/'.$this->paid().'/receipt')->assertForbidden();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/'.$this->paid().'/receipt?lang=fr')->assertUnprocessable();
    }
}

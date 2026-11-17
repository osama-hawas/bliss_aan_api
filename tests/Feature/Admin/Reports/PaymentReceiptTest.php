<?php

namespace Tests\Feature\Admin\Reports;

use App\Enums\AdminRole;
use App\Models\AppSetting;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Reports\Receipts\PaymentReceipts;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Slice 21 (S21): payment receipt PDFs (D324, D325). Needs S10's `payments` table: skipped until S10 is merged.
 * Rows are inserted with the slice-10 contract columns so this file does not depend on S10's classes.
 */
class PaymentReceiptTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private function payment(string $status = 'succeeded', string $amount = '1150.00', array $attributes = []): int
    {
        if (! Schema::hasTable('payments')) {
            $this->markTestSkipped('S10 payments not merged yet.');
        }

        $client = User::factory()->client()->create(['name' => 'Sara Client']);

        return DB::table('payments')->insertGetId([
            'reference' => 'PY-'.random_int(1000000, 9999999),
            'user_id' => $client->id,
            'payable_type' => 'offer',
            'payable_id' => 1,
            'purpose' => 'full',
            'amount' => $amount,
            'currency' => 'SAR',
            'method' => 'card',
            'card' => json_encode(['brand' => 'mada', 'last4' => '4242']),
            'gateway' => 'fake',
            'status' => $status,
            'fulfilment_status' => 'fulfilled',
            'refunded_amount' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_fingerprint' => str_repeat('a', 64),
            'paid_at' => $status === 'succeeded' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    private function vat(?int $percent): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'tax.vat_percent'], ['value' => $percent]);
        app(AppSettings::class)->flush();
    }

    public function test_receipt_pdf_is_issued_once_with_the_vat_snapshot(): void
    {
        $this->vat(15);
        $id = $this->payment();
        $admin = $this->staff(AdminRole::SuperAdmin);

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
        $receipt = app(PaymentReceipts::class)->issue($this->payment());

        $this->assertNull($receipt->vat_percent);
        $this->assertNull($receipt->vat_amount);
        $this->assertNull($receipt->issued_by);
    }

    public function test_only_succeeded_payments_have_receipts_and_finance_view_is_required(): void
    {
        $admin = $this->staff(AdminRole::SuperAdmin);

        $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/'.$this->payment('pending').'/receipt')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/999999/receipt')->assertNotFound();
        $this->actingAsApi($this->staff(AdminRole::OperationsSupport))->getJson('/api/v1/admin/payments/'.$this->payment().'/receipt')->assertForbidden();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/'.$this->payment().'/receipt?lang=fr')->assertUnprocessable();
    }
}

<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Enums\StoreReturnStatus;
use App\Exceptions\ApiException;
use App\Models\StoreReturn;
use App\Services\Store\Returns\StoreReturnProcessing;
use App\Services\Store\Returns\StoreReturnRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsStoreReturns;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * S23 locks with two real MySQL sessions (`slice-23-contract.md` §8): the other session holds the row the race is about;
 * this session must wait (1 s lock wait timeout), then decide on what the other one committed.
 * Lock order: return → order → variants (id asc) → (Slice 10) payment.
 */
class StoreReturnLockingTest extends TestCase
{
    use BuildsStoreReturns, UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->configureReturns(7);
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function other()
    {
        return DB::connection('other_session');
    }

    private function assertApiError(ErrorCode $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code->value}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function body(int $itemId, int $quantity): array
    {
        return ['reason_code' => 'damaged', 'note' => 'Broken', 'items' => [['order_item_id' => $itemId, 'quantity' => $quantity]]];
    }

    public function test_two_requests_never_return_more_than_she_bought(): void
    {
        $order = $this->deliveredOrder(); // one line, 2 bought
        $item = $order->items->first();

        // A parallel request holds her order row and takes both units.
        $this->other()->beginTransaction();
        $this->other()->select('select id from store_orders where id = ? for update', [$order->id]);
        $rivalId = $this->other()->table('store_returns')->insertGetId([
            'reference' => 'RT-9999999', 'store_order_id' => $order->id, 'user_id' => $order->user_id, 'status' => 'requested',
            'reason_code' => 'damaged', 'note' => 'x', 'subtotal' => '80.00', 'discount' => '0.00', 'refund_amount' => '80.00',
            'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->other()->table('store_return_items')->insert([
            'store_return_id' => $rivalId, 'store_order_item_id' => $item->id, 'product_variant_id' => $item->product_variant_id,
            'name' => json_encode($item->name), 'sku' => $item->sku, 'unit_price' => '40.00', 'quantity' => 2, 'line_total' => '80.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $create = fn () => app(StoreReturnRequests::class)->create($order->user, $order->id, (string) Str::uuid(), $this->body($item->id, 1));

        $this->shortLockWait();
        $this->assertWaits($create);
        $this->other()->commit();

        $this->assertApiError(ErrorCode::ValidationFailed, $create);
        $this->assertSame(1, StoreReturn::query()->count());
    }

    public function test_staff_approval_waits_for_a_client_cancel_holding_the_return(): void
    {
        $order = $this->deliveredOrder();
        [$return] = app(StoreReturnRequests::class)->create($order->user, $order->id, (string) Str::uuid(), $this->body($order->items->first()->id, 1));
        $staff = $this->superAdmin();

        // Her cancel holds the return row (first lock of every return move) and commits `cancelled`.
        $this->other()->beginTransaction();
        $this->other()->select('select id from store_returns where id = ? for update', [$return->id]);
        $this->other()->table('store_returns')->where('id', $return->id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $approve = fn () => app(StoreReturnProcessing::class)->approve($return->id, $staff, null);

        $this->shortLockWait();
        $this->assertWaits($approve);
        $this->other()->commit();

        $this->assertApiError(ErrorCode::InvalidState, $approve);
        $this->assertSame(StoreReturnStatus::Cancelled, $return->fresh()->status);
    }

    public function test_restock_waits_for_the_variant_row_and_adds_to_the_committed_count(): void
    {
        $order = $this->deliveredOrder();
        $item = $order->items->first();
        [$return] = app(StoreReturnRequests::class)->create($order->user, $order->id, (string) Str::uuid(), $this->body($item->id, 2));
        $staff = $this->superAdmin();
        app(StoreReturnProcessing::class)->approve($return->id, $staff, null);

        // A stock adjustment holds the variant row and sets a new count.
        $this->other()->beginTransaction();
        $this->other()->select('select id from product_variants where id = ? for update', [$item->product_variant_id]);
        $this->other()->table('product_variants')->where('id', $item->product_variant_id)->update(['stock' => 50]);

        $receive = fn () => app(StoreReturnProcessing::class)->receive($return->id, $staff, true, null);

        $this->shortLockWait();
        $this->assertWaits($receive);
        $this->other()->commit();

        $receive();
        $this->assertSame(52, DB::table('product_variants')->where('id', $item->product_variant_id)->value('stock'));
        $this->assertSame(StoreReturnStatus::Refunded, $return->fresh()->status);
    }

    public function test_the_refund_waits_for_a_staff_action_holding_the_order(): void
    {
        $order = $this->deliveredOrder();
        [$return] = app(StoreReturnRequests::class)->create($order->user, $order->id, (string) Str::uuid(), $this->body($order->items->first()->id, 1));
        $staff = $this->superAdmin();
        app(StoreReturnProcessing::class)->approve($return->id, $staff, null);
        app(StoreReturnProcessing::class)->receive($return->id, $staff, false, null); // refunded at once (fake gateway)
        $this->assertSame(StoreReturnStatus::Refunded, $return->fresh()->status);

        // A later return of the other unit: the refund reserve (order → payment) waits for the order row.
        [$second] = app(StoreReturnRequests::class)->create($order->user, $order->id, (string) Str::uuid(), $this->body($order->items->first()->id, 1));
        app(StoreReturnProcessing::class)->approve($second->id, $staff, null);
        DB::table('store_returns')->where('id', $second->id)->update(['status' => 'received', 'received_at' => now()]);

        $this->other()->beginTransaction();
        $this->other()->select('select id from store_orders where id = ? for update', [$order->id]);

        $refund = fn () => app(StoreReturnProcessing::class)->refund($second->id, $staff);

        $this->shortLockWait();
        $this->assertWaits($refund);
        $this->other()->commit();

        $refund();
        $this->assertSame(StoreReturnStatus::Refunded, $second->fresh()->status);
        $this->assertSame(2, DB::table('refunds')->where('status', 'refunded')->count());
    }
}

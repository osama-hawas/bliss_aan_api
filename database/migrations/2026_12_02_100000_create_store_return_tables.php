<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S23 store returns (OD37, `slice-23-contract.md` §3, `02-database-plan.md` §8). A return is a snapshot of the order
 * lines it takes back: names, options, SKU and unit prices never follow later changes. Money is decimal(12,2) on the
 * row and integer cents in every calculation. The refund itself is a Slice 10 `refunds` row (`refund_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique(); // RT-1234567
            $table->foreignId('store_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24); // StoreReturnStatus
            $table->string('reason_code', 24); // StoreReturnReason
            $table->text('note');
            $table->foreignId('policy_version_id')->nullable()->constrained('policy_versions')->nullOnDelete();
            $table->decimal('subtotal', 12, 2);       // Σ returned lines at the order's unit prices
            $table->decimal('discount', 12, 2)->default(0); // the order's promo discount, prorated (D345)
            $table->decimal('refund_amount', 12, 2);  // subtotal − discount; shipping is never refunded
            $table->unsignedBigInteger('refund_id')->nullable()->unique(); // the Slice 10 refund that settled it
            $table->unsignedTinyInteger('refund_attempts')->default(0);
            $table->string('refund_error', 64)->nullable(); // last refund failure (error code / gateway code)
            $table->boolean('restocked')->nullable();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['store_order_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });

        if (Schema::hasTable('refunds')) {
            Schema::table('store_returns', fn (Blueprint $table) => $table->foreign('refund_id')->references('id')->on('refunds')->restrictOnDelete());
        }

        Schema::create('store_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->json('name');                // snapshot of the order line
            $table->json('options')->nullable();
            $table->string('sku', 64)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->unique(['store_return_id', 'store_order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_return_items');
        Schema::dropIfExists('store_returns');
    }
};

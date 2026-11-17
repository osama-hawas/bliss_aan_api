<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 18 (S18, D274/D279): the stock history of each variant. Staff adjustments and the initial stock are written
 * here; S9b order deductions / restorations may append `order` / `order_cancelled` rows after the merge.
 * `store_order_id` has no FK yet: `store_orders` arrives with S9b (2026_11_24_100200); a later migration may add it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('source', 24); // StockMovementSource
            $table->string('reason_code', 24)->nullable(); // StockAdjustmentReason (adjustments only)
            $table->string('note', 500)->nullable();
            $table->integer('change');
            $table->unsignedInteger('stock_before');
            $table->unsignedInteger('stock_after');
            $table->unsignedBigInteger('store_order_id')->nullable()->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_variant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

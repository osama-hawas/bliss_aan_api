<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S18 fix round (D279): `stock_movements.store_order_id` → `store_orders` now that Slice 9b is merged.
 * Restrict on delete: orders are never deleted, and the stock history must keep its order reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('store_order_id')->references('id')->on('store_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['store_order_id']);
        });
    }
};

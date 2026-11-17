<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 9b store orders and shipments (`slice-9b-contract.md` §3.3, `02-database-plan.md` §8). Orders are snapshots:
 * address, recipient, items, prices and promo never follow later changes. Payment method / status live on the Slice 10
 * `payments` row (`payment_id` = the payment that confirmed the order); delivery method is the shipment's `method`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique(); // SO-1234567
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24); // StoreOrderStatus
            $table->json('address');       // {city_id, city {ar,en}, district, details, lat, lng}
            $table->string('recipient_name', 60);
            $table->string('recipient_phone', 20);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('shipping_fee', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('vat_percent', 5, 2)->nullable();
            $table->decimal('vat_amount', 12, 2)->nullable(); // included in total
            $table->json('promo')->nullable();                // {code, type, value}
            $table->unsignedBigInteger('payment_id')->nullable()->unique();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable(); // `stock_unavailable` or the staff reason
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });

        // The Slice 10 `payments` table runs first on a merged tree (2026_11_23_*); guarded so this branch also
        // migrates on its own before that merge.
        if (Schema::hasTable('payments')) {
            Schema::table('store_orders', fn (Blueprint $table) => $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete());
        }

        Schema::table('promo_code_usages', fn (Blueprint $table) => $table->foreign('store_order_id')->references('id')->on('store_orders')->cascadeOnDelete());

        Schema::create('store_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->json('name');                 // product name {ar, en}
            $table->json('options')->nullable();  // variant attributes {size|color: {ar, en}}
            $table->string('sku', 64);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_order_id')->constrained()->restrictOnDelete();
            $table->string('method', 16);  // ShipmentMethod
            $table->string('carrier', 32)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('status', 16)->index(); // ShipmentStatus
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64)->unique();
            $table->string('failure_reason', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_admin_id', 'status']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('external_event_id', 191)->unique();
            $table->string('status', 16);
            $table->string('carrier_status', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->json('raw')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('store_order_items');
        Schema::table('promo_code_usages', fn (Blueprint $table) => $table->dropForeign(['store_order_id']));
        Schema::dropIfExists('store_orders');
    }
};

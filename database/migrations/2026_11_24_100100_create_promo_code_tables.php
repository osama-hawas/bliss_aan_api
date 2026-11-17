<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 9b promo codes (D200, D212, D213; `slice-9b-contract.md` §3.2). Store orders only, one code per order, no cap,
 * no stacking. A use counts while `held` (open checkout) or `used` (paid order); `released` frees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // stored upper-case
            $table->string('type', 16);           // PromoCodeType
            $table->decimal('value', 12, 2);
            $table->decimal('min_amount', 12, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('promo_code_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_order_id')->unique(); // FK added with store_orders
            $table->string('status', 16); // PromoCodeUsageStatus
            $table->timestamps();

            $table->index(['promo_code_id', 'status']);
            $table->index(['promo_code_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_usages');
        Schema::dropIfExists('promo_codes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 9b (`slice-9b-contract.md` §3.1): admin sort for products (D209) and the price a cart line was set at, for the
 * "price changed" notice (D210).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('sort')->default(0)->index()->after('is_active');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            // Null for lines set before this column existed: no notice for them.
            $table->decimal('added_unit_price', 12, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', fn (Blueprint $table) => $table->dropColumn('added_unit_price'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sort']);
            $table->dropColumn('sort');
        });
    }
};

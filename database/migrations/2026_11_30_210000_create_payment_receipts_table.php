<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 21 (S21, D325): one receipt per succeeded payment, with the VAT snapshot it was issued with.
 * The FK to `payments` (S10) is added only when that table exists, so this migration also runs before S10 is merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id(); // receipt number RC-0000001 = id
            $table->unsignedBigInteger('payment_id')->unique();
            $table->decimal('amount', 12, 2);              // paid amount (VAT included, OD8)
            $table->char('currency', 3);
            $table->decimal('vat_percent', 5, 2)->nullable(); // null = no VAT line (tax.vat_percent not set)
            $table->decimal('vat_amount', 12, 2)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete(); // null = issued on payment
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        if (Schema::hasTable('payments')) {
            Schema::table('payment_receipts', fn (Blueprint $table) => $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};

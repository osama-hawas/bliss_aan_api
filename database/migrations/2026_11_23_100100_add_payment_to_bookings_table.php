<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 10 (D185): the payment that confirmed the booking. Unique: one payment fulfils at most one booking.
     * `payment_status` gains paid / partially_refunded / refunded (string column, no schema change).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('payment_status')->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};

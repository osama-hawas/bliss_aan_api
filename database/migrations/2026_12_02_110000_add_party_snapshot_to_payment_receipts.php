<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S22 (D338, owner's "middle solution"): a receipt keeps the payer's name and phone and the provider's name as they were
 * at the sale, so account deletion (which anonymises `users` / `providers`) never rewrites a financial document.
 * Existing receipts are back-filled from the current rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->string('payer_name')->nullable()->after('vat_amount');
            $table->string('payer_phone', 20)->nullable()->after('payer_name');
            $table->string('provider_name')->nullable()->after('payer_phone'); // booking payments only
        });

        if (! Schema::hasTable('payments')) {
            return;
        }
        DB::table('payment_receipts')->orderBy('id')->each(function (object $receipt) {
            $payment = DB::table('payments')->where('id', $receipt->payment_id)->first(['id', 'user_id']);
            $payer = $payment ? DB::table('users')->where('id', $payment->user_id)->where('status', '!=', 'deleted')->first(['name', 'phone']) : null;
            $provider = $payment && Schema::hasColumn('bookings', 'payment_id')
                ? DB::table('bookings')->join('providers', 'providers.id', '=', 'bookings.provider_id')
                    ->where('bookings.payment_id', $payment->id)->value('providers.display_name')
                : null;
            DB::table('payment_receipts')->where('id', $receipt->id)->update([
                'payer_name' => $payer?->name,
                'payer_phone' => $payer?->phone,
                'provider_name' => $provider,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropColumn(['payer_name', 'payer_phone', 'provider_name']);
        });
    }
};

<?php

use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client wallet money (owner decision 2026-11-19): idempotent ledger entries and direct top-ups until a gateway is
 * connected (never in production).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->after('meta');
            $table->unique(['wallet_id', 'idempotency_key']);
            $table->index(['wallet_id', 'id']);
        });

        // Payment on bookings stays the admin's switch (`payments.booking_payment_required` in Settings).
        $row = AppSetting::query()->firstOrCreate(['key' => 'wallet.direct_top_up'], ['value' => true]);
        if ($row->value === null) {
            $row->update(['value' => true]);
        }
        app(AppSettings::class)->flush();
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['wallet_id', 'idempotency_key']);
            $table->dropIndex(['wallet_id', 'id']);
            $table->dropColumn('idempotency_key');
        });
    }
};

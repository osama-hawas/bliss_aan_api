<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 19 review (L6): `ledger_adjustments.payout_request_id` now references Slice 11's `payout_requests`
     * (merged). Restrict on delete: payout requests are never deleted and an adjustment keeps its link.
     */
    public function up(): void
    {
        Schema::table('ledger_adjustments', function (Blueprint $table) {
            $table->foreign('payout_request_id')->references('id')->on('payout_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_adjustments', function (Blueprint $table) {
            $table->dropForeign(['payout_request_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client may reject one offer (QA BUG-012, owner decision 2026-11-19): when, and her optional reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('withdrawn_at');
            $table->string('rejection_reason', 500)->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['rejected_at', 'rejection_reason']);
        });
    }
};

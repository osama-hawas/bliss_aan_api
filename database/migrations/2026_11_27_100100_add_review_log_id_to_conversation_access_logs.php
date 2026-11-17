<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 17 review fix (D260): a follow-up chat page points at the log row that carried the reason (`review_log_id`;
 * null on that row itself), so the review window is measured from the reason, not from the latest page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_access_logs', function (Blueprint $table) {
            $table->foreignId('review_log_id')->nullable()->after('conversation_id')
                ->constrained('conversation_access_logs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_access_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_log_id');
        });
    }
};

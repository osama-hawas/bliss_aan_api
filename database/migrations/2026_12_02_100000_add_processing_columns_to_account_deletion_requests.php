<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S22 (D333): the last processing attempt that found open obligations — which ones and when. Cleared once processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_deletion_requests', function (Blueprint $table) {
            $table->json('blocked_by')->nullable()->after('internal_note'); // obligation keys → counts at the last check
            $table->timestamp('checked_at')->nullable()->after('blocked_by');
        });
    }

    public function down(): void
    {
        Schema::table('account_deletion_requests', function (Blueprint $table) {
            $table->dropColumn(['blocked_by', 'checked_at']);
        });
    }
};

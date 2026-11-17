<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 17 operations & support (slice-17-contract.md §3, D255–D265): the chat access log (Req 42, D260), ticket
 * resolve/close times (auto-close, D265), review moderation fields (D261) and content-report decisions (D262).
 * `cancellation_requests.requester_role` is a string column: the new `staff` value needs no schema change (D256).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->restrictOnDelete();
            $table->string('reason', 500);
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedBigInteger('before_message_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['ticket_id', 'created_at']);
            $table->index('conversation_id');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('last_message_at');
            $table->timestamp('closed_at')->nullable()->after('resolved_at');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('hidden_by')->nullable()->after('hidden_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable()->after('hidden_by');
            $table->text('moderation_note')->nullable()->after('hidden_at');
        });

        Schema::table('content_reports', function (Blueprint $table) {
            $table->string('decision', 20)->nullable()->after('status');
            $table->string('decision_reason', 500)->nullable()->after('decision');
            $table->text('internal_note')->nullable()->after('decision_reason');
        });
    }

    public function down(): void
    {
        Schema::table('content_reports', function (Blueprint $table) {
            $table->dropColumn(['decision', 'decision_reason', 'internal_note']);
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn(['hidden_at', 'moderation_note']);
        });
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['resolved_at', 'closed_at']);
        });
        Schema::dropIfExists('conversation_access_logs');
    }
};

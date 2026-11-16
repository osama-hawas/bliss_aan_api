<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 6 (docs/slice-6-contract.md §1, D125–D131): execution columns on bookings, cancellation requests,
     * reviews and review reports. No completion code (D125); progress updates are deferred (D130).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('started_by')->nullable()->after('started_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('completed_by');
        });

        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 10)->unique(); // CN-#######, display only
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('requester_role', 20); // client | provider (= inability, Req 24)
            $table->string('reason_code', 40);
            $table->text('reason_text');
            $table->string('status', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            // = booking_id while under_review, else null: at most one pending request per booking (last guard).
            $table->unsignedBigInteger('open_booking_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['booking_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 20); // published | hidden (hiding is Slice 17)
            $table->string('hidden_reason', 500)->nullable();
            $table->string('provider_reply', 500)->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status', 'created_at']);
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reportable_type', 40);
            $table->unsignedBigInteger('reportable_id');
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 40);
            $table->string('details', 300)->nullable();
            $table->string('status', 20); // open | handled
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->unique(['reportable_type', 'reportable_id', 'reporter_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('cancellation_requests');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn('cancelled_at');
        });
    }
};

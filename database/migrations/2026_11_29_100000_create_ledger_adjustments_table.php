<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 19 (D286–D292): staff adjustments of a provider's ledger. Posting one writes exactly one
     * `provider_ledger_entries` row (S11's table, `type = adjustment`, `entry_key adjustment:{id}`). Amounts are cents.
     * `payout_request_id` has no FK constraint: `payout_requests` belongs to Slice 11 (add it at merge if wanted).
     */
    public function up(): void
    {
        Schema::create('ledger_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason_code', 30);
            $table->text('reason');
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payout_request_id')->nullable()->index();
            // = payout_request_id while pending/posted, null once rejected/cancelled: one live return per payout.
            $table->unsignedBigInteger('payout_return_key')->nullable()->unique();
            $table->string('status', 20);
            $table->boolean('requires_approval');
            $table->json('limits')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('ledger_entry_key', 60)->nullable()->unique();
            $table->string('idempotency_key', 64);
            $table->string('idempotency_fingerprint', 64);
            $table->timestamps();

            $table->unique(['requested_by', 'idempotency_key'], 'ledger_adjustments_idempotency_unique');
            $table->index(['provider_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_adjustments');
    }
};

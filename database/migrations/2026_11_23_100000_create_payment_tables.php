<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 10 (docs/slice-10-contract.md §5): saved methods (gateway tokens only, D188), payments for a payable
     * (D185, D190), refunds (D187) and received webhook events (D189). Money is decimal(12,2); services compute in
     * integer cents.
     */
    public function up(): void
    {
        Schema::create('saved_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 30);
            $table->text('gateway_token'); // encrypted cast; never a card number (D188)
            $table->char('token_hash', 64);
            // = token_hash while not deleted, null once deleted: the same token is saved once per account.
            $table->char('active_token_hash', 64)->nullable();
            $table->string('brand', 20);
            $table->char('last4', 4);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->boolean('is_default')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['user_id', 'gateway', 'active_token_hash']);
            $table->index(['user_id', 'deleted_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('payable_type', 30); // PayableType value, resolved by PayableRegistry
            $table->unsignedBigInteger('payable_id');
            $table->string('purpose', 20);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('method', 20);
            $table->json('card')->nullable(); // {brand, last4, exp_month, exp_year} snapshot
            $table->foreignId('saved_payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 30);
            $table->string('gateway_reference', 100)->nullable()->unique();
            $table->string('status', 20);
            $table->string('failure_code', 40)->nullable();
            $table->text('next_action_url')->nullable();
            $table->string('fulfilment_status', 20);
            $table->string('fulfilment_error', 40)->nullable();
            $table->decimal('refunded_amount', 12, 2)->default(0); // reserved: in_progress + refunded
            $table->string('idempotency_key', 100);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'created_at']);
            $table->index(['fulfilment_status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20)->unique();
            $table->string('kind', 30);
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('status', 20);
            $table->string('gateway_reference', 100)->nullable()->unique();
            $table->string('failure_code', 40)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancellation_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 100);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30);
            $table->string('event_id', 150);
            $table->string('type', 60);
            $table->string('gateway_reference', 100)->nullable()->index();
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 20)->nullable(); // applied / ignored / unmatched
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('saved_payment_methods');
    }
};

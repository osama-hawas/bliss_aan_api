<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 5c (docs/slice-5c-contract.md §1): a request settled on one accepted offer. Snapshots only.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 10)->unique(); // BK-#######, display only
            $table->foreignId('service_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('execution_place', 20);
            $table->timestamp('starts_at'); // copied from the request (D107)
            $table->timestamp('ends_at');
            $table->json('address')->nullable(); // request snapshot; null at the provider's location
            $table->string('title', 120);
            $table->decimal('total', 10, 2);
            $table->string('status', 20);
            $table->string('payment_status', 20); // not_required in 5c (D113)
            $table->foreignId('policy_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamps();

            $table->unique(['client_id', 'idempotency_key']);
            $table->index(['provider_id', 'status', 'starts_at']);
            $table->index(['client_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};

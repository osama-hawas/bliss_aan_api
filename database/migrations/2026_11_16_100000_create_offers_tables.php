<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 5b (docs/slice-5b-contract.md §1): offers and their line items. Snapshots: never repriced.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 9)->unique(); // Q-#######, display only (like D72)
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('title', 120);
            $table->decimal('total', 10, 2);
            $table->unsignedTinyInteger('validity_days'); // 3 / 7 / 14 (D5)
            $table->timestamp('valid_until');
            $table->text('notes')->nullable();
            $table->boolean('is_quick')->default(false); // C2 open: always false in 5b
            $table->string('status', 20);
            $table->foreignId('policy_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('sent_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamps();

            $table->unique(['provider_id', 'idempotency_key']);
            $table->index(['service_request_id', 'status']);
            $table->index(['status', 'valid_until']);
        });

        Schema::create('offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('description', 150);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_items');
        Schema::dropIfExists('offers');
    }
};

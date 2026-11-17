<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 12b support tickets (slice-12b-contract.md §3, D157–D163). Mobile users open and reply; staff handling is
 * Slice 17 (`assigned_to`, `is_internal` stay unused here). `related_type` holds the API keys `service_request` /
 * `booking` (TicketRelatedType), not class names, so there is no foreign key on the related record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('topic', 30);
            $table->string('subject', 80);
            $table->string('related_type', 30)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->index();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['related_type', 'related_id']);
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->uuid('client_uuid')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['author_id', 'client_uuid']);
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};

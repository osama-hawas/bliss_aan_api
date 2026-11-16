<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 8: Laravel's database notifications table (02-database-plan.md §9) plus `dedupe_key`, unique per recipient,
 * so a retried transaction or re-run job never stores a second row (D139).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 64);
            $table->morphs('notifiable');
            $table->json('data');
            $table->string('dedupe_key', 150);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key'], 'notifications_recipient_dedupe_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_recipient_read_index');
            $table->index(['read_at', 'created_at'], 'notifications_prune_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

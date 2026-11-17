<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 20 (D305): staff broadcast campaigns, delivered through the Slice 8 Notifier.
     */
    public function up(): void
    {
        Schema::create('broadcast_campaigns', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->json('body');
            $table->string('app', 20)->index();
            $table->foreignId('city_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('recipients_count')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_campaigns');
    }
};

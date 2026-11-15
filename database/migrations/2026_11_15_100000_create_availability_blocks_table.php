<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Periods a provider marked as unavailable (Slice 5a, §3.1). Half-open UTC intervals [starts_at, ends_at);
     * soft-deleted rows keep the history and affect nothing (D94).
     */
    public function up(): void
    {
        Schema::create('availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider_id', 'deleted_at', 'starts_at', 'ends_at'], 'availability_blocks_provider_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_blocks');
    }
};

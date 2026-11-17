<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 12a (D150, D151, D155): a client's or provider's request to delete her account. Stored and withdrawable;
     * nobody processes it until Q76 is answered, so the processing columns stay null.
     */
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // = user_id while pending, null once closed: one pending request per account (no partial unique index in MySQL).
            $table->unsignedBigInteger('open_user_id')->nullable()->unique();
            $table->string('reason_code', 40);
            $table->text('details')->nullable();
            $table->string('status', 20);
            $table->string('user_type', 20);
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider onboarding (Slice 3): documents per configured type, the reviewer's notes and completion
     * requests, and the provider's status reason / submission time.
     */
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            // The user-visible reason of the latest suspension (`null` when not suspended).
            $table->string('status_reason', 1000)->nullable()->after('status');
            // First and latest submission to the review queue; the admin queue sorts by it.
            $table->timestamp('submitted_at')->nullable()->index()->after('status_reason');
        });

        Schema::create('provider_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('rejection_reason', 1000)->nullable(); // shown to the provider
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One file per document type; a new upload replaces a pending or rejected one.
            $table->unique(['provider_id', 'document_type_id']);
        });

        Schema::create('provider_review_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note_to_provider')->nullable();
            $table->text('internal_note')->nullable(); // staff only, never sent to the provider
            // Set when the note asks the provider to complete the request (`needs_completion`).
            $table->json('completion_items')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_review_notes');
        Schema::dropIfExists('provider_documents');
        Schema::table('providers', function (Blueprint $table) {
            $table->dropIndex(['submitted_at']);
            $table->dropColumn(['status_reason', 'submitted_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-cutting tables: history, audit, policies, settings, media, content.
     */
    public function up(): void
    {
        // Drives "view history" on requests/offers/bookings/orders and the operational trail.
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('event', 100);
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 30)->nullable();
            $table->string('visibility', 20)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        // Admin and sensitive actions, with before/after values.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('action', 100)->index();
            $table->text('reason')->nullable();
            $table->json('old')->nullable();
            $table->json('new')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        // Undecided business numbers live here, versioned; snapshots point at a version.
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->unsignedInteger('version');
            $table->json('content')->nullable();
            $table->json('values')->nullable();
            $table->timestamp('effective_from');
            $table->timestamps();

            $table->unique(['type', 'version']);
            $table->index(['type', 'effective_from']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable(); // null = not specified yet
            $table->timestamps();
        });

        // Uploaded first (unattached), then attached to a model by the feature that uses it.
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('attachable');
            $table->string('collection', 50)->nullable();
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('duration')->nullable(); // seconds, for voice notes
            $table->string('visibility', 20);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('audience', 20);
            $table->json('title');
            $table->json('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['slug', 'audience']);
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->json('question');
            $table->json('answer');
            $table->string('audience', 20)->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->nullableMorphs('destination');
            $table->string('app', 20)->index();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('media');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('activity_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-owned service catalog. Images are `media` rows (collection `image`).
     */
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->decimal('guide_price', 12, 2)->nullable(); // null = not set by admin yet
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('service_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->json('text');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('addon_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['addon_id', 'service_id']);
        });

        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Q6 open: the required document list is undecided, so it is configurable data.
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('applies_to', 20)->index();
            $table->boolean('is_required')->default(false);
            $table->json('name');
            $table->json('allowed_mimes')->nullable();
            $table->unsignedInteger('max_size_kb')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('event_types');
        Schema::dropIfExists('addon_service');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('service_inclusions');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};

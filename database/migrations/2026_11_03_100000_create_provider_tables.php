<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Providers and what clients discover about them (Slice 2). Documents, working hours,
     * availability blocks and review notes come with onboarding (Slice 3).
     * Avatar/cover images are `media` rows (collections `avatar`, `cover`).
     */
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('account_type', 20);
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('representative_name')->nullable();
            $table->string('representative_title')->nullable();
            $table->text('bio')->nullable(); // user-written, never translated
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('started_year')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->boolean('is_verified')->default(false); // admin only
            $table->boolean('is_featured')->default(false)->index(); // admin only (D32)
            $table->boolean('accepts_open_requests')->default(true);
            $table->boolean('quick_offer_enabled')->default(false);
            $table->decimal('rating_avg', 3, 2)->nullable(); // cache; null = no reviews yet
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('response_time_minutes')->nullable(); // cache
            $table->timestamp('activated_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('provider_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->decimal('custom_price', 12, 2)->nullable(); // null = follow the guide price
            $table->boolean('at_provider_location')->default(false);
            $table->boolean('at_event_location')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['provider_id', 'service_id']);
        });

        // A missing row means the catalog inclusion applies.
        Schema::create('provider_service_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_inclusion_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['provider_service_id', 'service_inclusion_id'], 'provider_service_inclusion_unique');
        });

        Schema::create('provider_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['provider_id', 'addon_id']);
        });

        Schema::create('provider_addon_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['provider_addon_id', 'provider_service_id'], 'provider_addon_service_unique');
        });

        // Q7 open: district_id stays null until districts are decided; matching is by city.
        Schema::create('provider_coverage_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['city_id', 'provider_id']);
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('caption')->nullable();
            $table->boolean('hidden_by_admin')->default(false);
            $table->string('hidden_reason')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('favoritable_type', 30); // App\Enums\FavoriteType
            $table->unsignedBigInteger('favoritable_id');
            $table->timestamps();

            $table->unique(['user_id', 'favoritable_type', 'favoritable_id']);
            $table->index(['favoritable_type', 'favoritable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('portfolio_items');
        Schema::dropIfExists('provider_coverage_areas');
        Schema::dropIfExists('provider_addon_service');
        Schema::dropIfExists('provider_addons');
        Schema::dropIfExists('provider_service_inclusions');
        Schema::dropIfExists('provider_services');
        Schema::dropIfExists('providers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 4 (docs/slice-4-contract.md §5): client addresses, decline reasons, the guest-count mode of a catalog
     * service (D54), and service requests with their add-on snapshots and recipients.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60)->nullable();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('district', 100)->nullable(); // free text, D59
            $table->string('details', 255);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('recipient_name', 60)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            // One default per user, enforced in AddressBook under a row lock (no partial unique index in MySQL).
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_default']);
        });

        Schema::create('decline_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->json('name');
            $table->boolean('is_active')->default(true); // deactivated, never deleted (D51)
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('guest_count_mode', 10)->default('optional')->after('guide_price'); // D54
        });

        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 10)->unique(); // RN-#######, display only (D72)
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 10);
            $table->foreignId('target_provider_id')->nullable()->constrained('providers')->restrictOnDelete();
            $table->foreignId('provider_service_id')->nullable()->constrained('provider_services')->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_type_id')->constrained()->restrictOnDelete();
            $table->string('execution_place', 20);
            $table->date('event_date');           // as entered, Asia/Riyadh
            $table->time('start_time');           // as entered, Asia/Riyadh
            $table->unsignedInteger('duration_minutes');
            $table->timestamp('starts_at');       // UTC (D50)
            $table->timestamp('ends_at');         // UTC, starts_at + duration (D48, may cross midnight D61)
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete(); // source only
            $table->json('address')->nullable(); // snapshot {label, district, details, lat, lng}; null for provider_location (D66)
            $table->unsignedInteger('guests_count')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20);
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamps();

            $table->unique(['client_id', 'idempotency_key']);
            $table->index('status');
            $table->index(['client_id', 'created_at']);
            $table->index('city_id');
            $table->index('starts_at');
        });

        Schema::create('service_request_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_addon_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name'); // snapshot {ar, en}
            $table->timestamps();

            $table->unique(['service_request_id', 'addon_id']);
        });

        Schema::create('service_request_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->foreignId('decline_reason_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('decline_explanation')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['service_request_id', 'provider_id']);
            $table->index(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_recipients');
        Schema::dropIfExists('service_request_addons');
        Schema::dropIfExists('service_requests');
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('guest_count_mode');
        });
        Schema::dropIfExists('decline_reasons');
        Schema::dropIfExists('addresses');
    }
};

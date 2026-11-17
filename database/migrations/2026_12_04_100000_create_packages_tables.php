<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 24 — service packages (OD39, docs/slice-24-contract.md §3). A package is an admin-curated, bilingual bundle of
 * catalog services with no price (D350). Packages and their rows are never deleted (stopped / activated, D180);
 * `service_requests.package_id` keeps which package a request came from (D353).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('stop_reason', 500)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['package_id', 'service_id']);
        });

        // D351: optional occasions; none = suitable for every occasion.
        Schema::create('event_type_package', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_type_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['package_id', 'event_type_id']);
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('service_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
        Schema::dropIfExists('event_type_package');
        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
    }
};

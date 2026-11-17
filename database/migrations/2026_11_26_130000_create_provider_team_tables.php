<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 13 (docs/slice-13-contract.md §1, D230–D236): provider team members (records only, never log in) and the
 * informational booking assignments only the provider sees. Nothing is deleted when a member is disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // future login only; always null (D230)
            $table->string('name', 100);
            $table->string('phone', 20)->nullable();
            $table->string('title', 60);
            $table->string('status', 20)->default('active');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
        });

        Schema::create('booking_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_member_id')->constrained('provider_team_members')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['booking_id', 'team_member_id']);
            $table->index('team_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_assignments');
        Schema::dropIfExists('provider_team_members');
    }
};

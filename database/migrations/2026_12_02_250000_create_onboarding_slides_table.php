<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 25 (D360): onboarding slides of each mobile app, managed from the dashboard. Image = a media row attached
     * to the slide (collection `image`). Hidden, never deleted.
     */
    public function up(): void
    {
        Schema::create('onboarding_slides', function (Blueprint $table) {
            $table->id();
            $table->string('app', 20);
            $table->json('title');
            $table->json('text');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(false);
            $table->text('hidden_reason')->nullable();
            $table->timestamps();

            $table->index(['app', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_slides');
    }
};

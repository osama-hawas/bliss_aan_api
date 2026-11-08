<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weekly opening hours a provider sets up after activation (Slice 3 setup). A weekday without a row is closed.
     */
    public function up(): void
    {
        Schema::create('provider_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday … 6 = Saturday
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->unique(['provider_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_working_hours');
    }
};

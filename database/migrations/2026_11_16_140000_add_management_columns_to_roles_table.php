<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 14b (D168): custom roles get an AR/EN label; the five AdminRole roles are marked as system roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('label')->nullable()->after('guard_name'); // {ar, en}; null for system roles (labels in lang/*/enums.php)
            $table->boolean('is_system')->default(false)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'is_system']);
        });
    }
};

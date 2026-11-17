<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 16 catalog management (docs/slice-16-contract.md §3): inclusions are switched off, never deleted (D179);
     * add-ons get a display order (D183b). Additive only.
     */
    public function up(): void
    {
        Schema::table('service_inclusions', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('sort')->index();
        });

        Schema::table('addons', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn('sort');
        });

        Schema::table('service_inclusions', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};

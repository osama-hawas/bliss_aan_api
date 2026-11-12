<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D73: offering a service at the client's event location must be confirmed by the provider.
     * New rows start off; the confirmation is its own column. No backfill: existing `true` rows keep their
     * value (direct requests still read it) but stay unconfirmed until she reviews them.
     */
    public function up(): void
    {
        Schema::table('provider_services', function (Blueprint $table) {
            $table->boolean('at_event_location')->default(false)->change();
            $table->timestamp('event_location_confirmed_at')->nullable()->after('at_event_location');
        });
    }

    public function down(): void
    {
        Schema::table('provider_services', function (Blueprint $table) {
            $table->dropColumn('event_location_confirmed_at');
            $table->boolean('at_event_location')->default(true)->change();
        });
    }
};

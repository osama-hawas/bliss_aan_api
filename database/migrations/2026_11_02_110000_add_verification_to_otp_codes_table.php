<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registration is two steps: a verified OTP returns a one-time registration token
     * (sha256 stored), which `POST auth/register` exchanges for the account.
     */
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('expires_at');
            $table->string('verification_token_hash', 64)->nullable()->unique()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropUnique(['verification_token_hash']);
            $table->dropColumn(['verified_at', 'verification_token_hash']);
        });
    }
};

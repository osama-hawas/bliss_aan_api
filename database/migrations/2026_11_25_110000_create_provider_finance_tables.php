<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 11 (slice-11-contract.md §3, D215–D218): the provider earnings ledger, her payout account and payout
     * requests. Every money column is integer cents (D216). There is no balance column: balances are sums of the
     * append-only ledger.
     */
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->unique()->constrained()->restrictOnDelete(); // one account per provider
            $table->string('holder_name', 70);
            $table->text('iban');                 // encrypted cast
            $table->char('iban_last4', 4);
            $table->char('bank_code', 2);
            $table->string('bank_name_key', 40)->nullable(); // config/saudi_banks.php key; null = unknown bank code
            $table->timestamp('verified_at')->nullable();      // no bank verification exists yet
            $table->timestamps();
        });

        Schema::create('payout_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique(); // PO-#######
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('status', 20); // requested / paid / rejected
            // Snapshot of the payout account when she asked (later account edits never change it).
            $table->string('holder_name', 70);
            $table->text('iban'); // encrypted cast
            $table->char('iban_last4', 4);
            $table->string('bank_name_key', 40)->nullable();
            // = transfer_reference while paid, else null: a bank transfer reference is used by one paid request only.
            $table->string('transfer_reference', 64)->nullable();
            $table->string('paid_transfer_reference', 64)->nullable()->unique();
            $table->text('rejection_reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->uuid('idempotency_key');
            $table->char('idempotency_fingerprint', 64);
            // = provider_id while requested, else null: one open request per provider (D221).
            $table->unsignedBigInteger('open_provider_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['provider_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('provider_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // earning / refund_adjustment / payout / payout_reversal
            $table->bigInteger('amount_cents'); // signed net effect on her balance
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payout_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('gross_cents')->nullable();
            $table->bigInteger('commission_cents')->nullable();
            $table->unsignedSmallInteger('commission_bps')->nullable(); // percent × 100, snapshot
            $table->timestamp('available_at');
            $table->string('entry_key', 80)->unique(); // idempotency: earning:{booking} / refund:{refund} / payout:{id} / payout_reversal:{id}
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'available_at']);
            $table->index(['provider_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_ledger_entries');
        Schema::dropIfExists('payout_requests');
        Schema::dropIfExists('payout_accounts');
    }
};

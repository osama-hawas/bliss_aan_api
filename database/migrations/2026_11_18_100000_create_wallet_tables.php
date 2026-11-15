<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallets for clients and providers (D106, docs/wallet-contract.md): CRUD only, no money moves. The balance is
     * never stored: it is derived from ledger entries, and nothing writes entries in this scope.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('owner_type', 10); // client / provider (users.type at creation)
            // = user_id while the wallet is active, null once closed: one active wallet per account (unique allows
            // many nulls), closed wallets are kept as history (nothing with history is hard-deleted, D106).
            $table->unsignedBigInteger('active_owner_id')->nullable()->unique();
            $table->string('label', 60)->nullable(); // the only editable information
            $table->char('currency', 3);
            $table->string('status', 10); // active / closed
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Extension point for future financial operations. No endpoint or job writes it in the D106 scope.
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2); // signed
            $table->string('status', 20);     // only `available` entries count towards the balance
            $table->nullableMorphs('source');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallets');
    }
};

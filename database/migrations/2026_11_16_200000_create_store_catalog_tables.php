<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 9 (store, independent part): products and their variants (`02-database-plan.md` §8, `slice-9-contract.md` §3).
 * Depends only on `product_categories` (2026_11_02). No `rating_avg`: product reviews are open (S4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->decimal('base_price', 12, 2);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64)->unique();
            // Attribute code (size / color) => {ar, en} value; null for a product's single plain variant.
            $table->json('attributes')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};

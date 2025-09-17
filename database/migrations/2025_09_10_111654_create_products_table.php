<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// database/migrations/2025_09_10_000020_create_products_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('title');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('weight', 8, 3)->nullable(); // kg
            $table->timestamps();
        });


        // inventory per branch
        Schema::create('product_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->integer('stock')->default(0);
            $table->integer('stock_alert')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
        });
    }
    public function down(): void 
    {
        Schema::dropIfExists('product_branch');
        Schema::dropIfExists('products');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // 1. Drop foreign key first if it exists (check your exact constraint name if this fails)
            // Usually it is order_items_product_id_foreign
            $table->dropForeign(['product_id']); 
            
            // 2. Make product_id nullable
            $table->foreignId('product_id')->nullable()->change();
            
            // 3. Add foreign key back (optional, but good practice)
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            // 4. Add product_name column
            $table->string('product_name')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
            $table->dropColumn('product_name');
        });
    }
};
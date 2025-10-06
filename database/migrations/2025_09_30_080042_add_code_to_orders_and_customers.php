<?php

// database/migrations/2025_09_30_000001_add_code_to_orders_and_customers.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('code', 32)->nullable()->unique()->after('id'); // TLOxxxxxx
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->string('code', 32)->nullable()->unique()->after('id'); // TLKCxxxxxx
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('code');
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn('code');
        });
    }
};
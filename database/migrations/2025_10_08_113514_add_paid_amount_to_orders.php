<?php
// database/migrations/2025_10_08_000002_add_paid_amount_to_orders.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->nullable()->after('payment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
        });
    }
};

<?php
// database/migrations/2025_10_28_000001_widen_money_precision.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('order_items', function (Blueprint $table) {
            // keep totals at 2dp, but store unit_price at 4dp to avoid FX round-trip loss
            $table->decimal('unit_price', 12, 4)->change();
            // optional but recommended for nicer totals: allow fractional qty
            $table->decimal('qty', 12, 3)->change();
            // line_total should stay at 2dp
            // $table->decimal('line_total', 12, 2)->change(); // keep as-is
        });

        // good practice: store exchange_rate with more precision if not already
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('exchange_rate', 18, 8)->change(); // you had :6; :8 is safer
        });
    }

    public function down(): void {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 2)->change();
            $table->decimal('qty', 12, 2)->change();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('exchange_rate', 12, 6)->change();
        });
    }
};

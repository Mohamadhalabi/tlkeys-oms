<?php
// database/migrations/2025_10_08_000001_make_customer_id_nullable_on_orders.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Drop existing FK first (safe even if it doesn't exist)
            $table->dropForeign(['customer_id']);

            // Make column nullable
            $table->unsignedBigInteger('customer_id')->nullable()->change();

            // Recreate FK and set null on delete
            $table->foreign('customer_id')
                ->references('id')->on('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->foreign('customer_id')
                ->references('id')->on('customers')
                ->restrictOnDelete();
        });
    }
};

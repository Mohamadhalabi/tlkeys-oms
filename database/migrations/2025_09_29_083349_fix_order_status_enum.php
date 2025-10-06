<?php
// database/migrations/2025_09_29_000000_fix_order_status_enum.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // adjust as needed for your full set of statuses
            $table->enum('status', [
                'on_hold', 'draft', 'pending', 'processing', 'completed',
                'cancelled', 'refunded', 'failed'
            ])->default('on_hold')->change();

            $table->enum('type', ['order','proforma'])
                  ->default('proforma')
                  ->change();
        });
    }

    public function down(): void
    {
        // revert to previous definition if you had it (fill in accordingly)
    }
};

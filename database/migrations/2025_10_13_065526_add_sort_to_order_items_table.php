<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('sort')->nullable()->after('id');
        });

        // Backfill: set sort = 1..N per order_id
        // Try SQL window function first (MySQL 8+/MariaDB 10.2+ with window functions):
        try {
            DB::statement("
                UPDATE order_items oi
                JOIN (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY order_id ORDER BY id) AS rn
                    FROM order_items
                ) x ON x.id = oi.id
                SET oi.sort = x.rn
            ");
        } catch (\Throwable $e) {
            // Fallback (no window functions): do it in PHP
            $rows = DB::table('order_items')->select('id', 'order_id')->orderBy('order_id')->orderBy('id')->get();
            $counters = [];
            foreach ($rows as $r) {
                $counters[$r->order_id] = ($counters[$r->order_id] ?? 0) + 1;
                DB::table('order_items')->where('id', $r->id)->update(['sort' => $counters[$r->order_id]]);
            }
        }

        // Make it not-null after backfill (optional but nice)
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('sort')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};

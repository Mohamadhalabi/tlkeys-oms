<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add order_items.note (after line_total if present)
        if (!Schema::hasColumn('order_items', 'note')) {
            Schema::table('order_items', function (Blueprint $table) {
                // place after line_total if it exists, otherwise just add
                if (Schema::hasColumn('order_items', 'line_total')) {
                    $table->text('note')->nullable()->after('line_total');
                } else {
                    $table->text('note')->nullable();
                }
            });
        }

        // Add orders.invoice_note (choose an anchor that exists)
        if (!Schema::hasColumn('orders', 'invoice_note')) {
            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'extra_fees_percent')) {
                    $table->text('invoice_note')->nullable()->after('extra_fees_percent');
                } elseif (Schema::hasColumn('orders', 'shipping')) {
                    $table->text('invoice_note')->nullable()->after('shipping');
                } elseif (Schema::hasColumn('orders', 'discount')) {
                    $table->text('invoice_note')->nullable()->after('discount');
                } else {
                    // Fallback with no explicit position
                    $table->text('invoice_note')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'note')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }

        if (Schema::hasColumn('orders', 'invoice_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('invoice_note');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'can_see_cost')) {
                $table->boolean('can_see_cost')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('users', 'can_sell_below_cost')) {
                $table->boolean('can_sell_below_cost')->default(false)->after('can_see_cost');
            }
            // You already have branch_id. If not, uncomment:
            // $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'can_see_cost', 'can_sell_below_cost']);
        });
    }
};

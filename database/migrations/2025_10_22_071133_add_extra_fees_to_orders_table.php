<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('extra_fees', 12, 4)->default(0)->after('shipping');
            $table->decimal('extra_fees_local', 12, 4)->default(0)->after('extra_fees');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
    $table->dropColumn(['extra_fees', 'extra_fees_local']);
        });
    }
};
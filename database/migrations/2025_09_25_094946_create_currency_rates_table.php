<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->string('code', 3)->primary();           // e.g. USD, TRY, AED, SAR, EUR
            $table->string('name');                          // e.g. US Dollar
            $table->decimal('usd_to_currency', 14, 6);       // 1 USD = X <code>
            $table->timestamps();
        });

        // Add currency fields to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('total');
            $table->decimal('exchange_rate', 14, 6)->default(1)->after('currency'); // 1 USD = X currency
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });

        Schema::dropIfExists('currency_rates');
    }
};

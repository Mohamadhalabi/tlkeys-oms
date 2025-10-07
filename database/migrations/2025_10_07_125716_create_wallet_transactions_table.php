<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['credit', 'debit'])->default('credit');
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // If your customers table doesn’t already have a wallet_balance column, uncomment:
        // Schema::table('customers', function (Blueprint $table) {
        //     $table->decimal('wallet_balance', 14, 2)->default(0)->after('address');
        // });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        // If you added wallet_balance in up(), also drop it here.
        // Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('wallet_balance'));
    }
};

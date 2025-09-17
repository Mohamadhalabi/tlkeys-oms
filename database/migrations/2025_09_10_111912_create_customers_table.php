<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable(); // simple for now; can normalize later
            $table->decimal('wallet_balance', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2); // +credit, -debit
            $table->string('type'); // credit|debit
            $table->string('reference')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void 
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('customers');
    }
};

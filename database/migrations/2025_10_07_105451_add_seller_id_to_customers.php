<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->foreignId('seller_id')->nullable()
              ->constrained('users')->nullOnDelete()->index();
        });
    }
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('seller_id');
        });
    }
};
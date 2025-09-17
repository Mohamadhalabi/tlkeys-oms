<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'image')) {
                $table->string('image')->nullable()->after('weight');
            }

            if (Schema::hasColumn('products', 'description')) {
                $table->dropColumn('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'image')) {
                $table->dropColumn('image');
            }

            if (! Schema::hasColumn('products', 'description')) {
                // if you want it back, keep it json & nullable
                $table->json('description')->nullable()->after('title');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Ensure description JSON column exists
        if (! Schema::hasColumn('products', 'description')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('description')->nullable()->after('title');
            });
        }

        // 2) Ensure title is JSON
        // If title already JSON, do nothing. Otherwise convert.
        // We'll detect by trying to add a temp column and move data.
        $columns = DB::select("
            SELECT DATA_TYPE AS type
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'products'
              AND COLUMN_NAME = 'title'
        ");

        $type = strtolower($columns[0]->type ?? '');

        if ($type !== 'json') {
            // Add temp JSON column
            if (! Schema::hasColumn('products', '_title_json')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->json('_title_json')->nullable()->after('title');
                });
            }

            // Copy old string title into JSON as {"en": "<old>"}
            DB::statement("
                UPDATE products
                SET _title_json = CASE
                    WHEN title IS NULL OR title = '' THEN NULL
                    ELSE JSON_OBJECT('en', title)
                END
            ");

            // Drop old column and rename temp to title
            // (raw SQL avoids needing doctrine/dbal)
            DB::statement("ALTER TABLE products DROP COLUMN title");
            DB::statement("ALTER TABLE products CHANGE COLUMN _title_json title JSON NULL");
        }
    }

    public function down(): void
    {
        // Revert title back to VARCHAR(255)
        $columns = DB::select("
            SELECT DATA_TYPE AS type
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'products'
              AND COLUMN_NAME = 'title'
        ");

        $type = strtolower($columns[0]->type ?? '');
        if ($type === 'json') {
            // create temp varchar, copy en value back, drop json, rename
            DB::statement("ALTER TABLE products ADD COLUMN _title_varchar VARCHAR(255) NULL AFTER title");
            DB::statement("
                UPDATE products
                SET _title_varchar = CASE
                    WHEN JSON_EXTRACT(title, '$.en') IS NULL THEN NULL
                    ELSE JSON_UNQUOTE(JSON_EXTRACT(title, '$.en'))
                END
            ");
            DB::statement("ALTER TABLE products DROP COLUMN title");
            DB::statement("ALTER TABLE products CHANGE COLUMN _title_varchar title VARCHAR(255) NULL");
        }

        // Drop description if we added it
        if (Schema::hasColumn('products', 'description')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('style_profiles', 'handle')) {
            Schema::table('style_profiles', function (Blueprint $table) {
                $table->string('handle')->nullable()->index()->after('product_id');
            });
        }

        if (Schema::hasColumn('style_profiles', 'handle')) {
            DB::table('style_profiles')
                ->join('products', 'style_profiles.product_id', '=', 'products.id')
                ->update(['style_profiles.handle' => DB::raw('products.handle')]);
        }

        $this->ensureIndex('style_profiles', 'style_profiles_product_id_index', ['product_id']);

        Schema::table('style_profiles', function (Blueprint $table) {
            $table->dropUnique('style_profiles_product_id_sku_unique');
            $table->unique(['handle', 'sku'], 'style_profiles_handle_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('style_profiles', function (Blueprint $table) {
            $table->dropUnique('style_profiles_handle_sku_unique');
            $table->unique(['product_id', 'sku'], 'style_profiles_product_id_sku_unique');
            $table->dropIndex(['handle']);
            $table->dropColumn('handle');
        });
    }

    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        $exists = DB::select('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?', [$indexName]);
        if (!empty($exists)) {
            return;
        }

        $cols = implode(',', array_map(fn ($col) => "`{$col}`", $columns));
        DB::statement("CREATE INDEX `{$indexName}` ON `{$table}` ({$cols})");
    }
};

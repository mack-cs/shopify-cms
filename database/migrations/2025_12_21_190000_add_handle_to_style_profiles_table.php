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
                ->update([
                    'handle' => DB::raw('(select products.handle from products where products.id = style_profiles.product_id)'),
                ]);
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
        if (Schema::hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }
};

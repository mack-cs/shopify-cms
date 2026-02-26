<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('shopify_taxonomy_gid', 255)
                ->nullable()
                ->after('google_product_category');
            $table->unique('shopify_taxonomy_gid');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['shopify_taxonomy_gid']);
            $table->dropColumn('shopify_taxonomy_gid');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->longText('tags')->nullable()->after('vendor');
            $table->string('type', 255)->nullable()->after('tags');
            $table->string('published', 32)->nullable()->after('type');
            $table->string('google_product_category', 255)->nullable()->after('product_category');
            $table->string('color_string', 512)->nullable()->after('seo_description');
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->dropColumn([
                'tags',
                'type',
                'published',
                'google_product_category',
                'color_string',
            ]);
        });
    }
};

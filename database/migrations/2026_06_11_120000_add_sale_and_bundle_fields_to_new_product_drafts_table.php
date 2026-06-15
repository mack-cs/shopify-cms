<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            if (!Schema::hasColumn('new_product_drafts', 'is_on_sale')) {
                $table->boolean('is_on_sale')
                    ->default(false)
                    ->after('tags')
                    ->index();
            }

            if (!Schema::hasColumn('new_product_drafts', 'bundle_product_ids')) {
                $table->json('bundle_product_ids')
                    ->nullable()
                    ->after('complementary_products');
            }

            if (!Schema::hasColumn('new_product_drafts', 'bundle_image_urls')) {
                $table->json('bundle_image_urls')
                    ->nullable()
                    ->after('bundle_product_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('new_product_drafts', 'bundle_image_urls')) {
                $table->dropColumn('bundle_image_urls');
            }

            if (Schema::hasColumn('new_product_drafts', 'bundle_product_ids')) {
                $table->dropColumn('bundle_product_ids');
            }

            if (Schema::hasColumn('new_product_drafts', 'is_on_sale')) {
                $table->dropIndex(['is_on_sale']);
                $table->dropColumn('is_on_sale');
            }
        });
    }
};

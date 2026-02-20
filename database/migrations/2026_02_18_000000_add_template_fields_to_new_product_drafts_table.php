<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->decimal('material_cost', 10, 2)->nullable()->after('variant_inventory_qty');
            $table->string('jewelry_material', 255)->nullable()->after('material_cost');
            $table->string('product_materials', 255)->nullable()->after('jewelry_material');
            $table->string('materials_and_dimensions', 512)->nullable()->after('product_materials');
            $table->string('product_design', 255)->nullable()->after('materials_and_dimensions');
            $table->string('metal', 255)->nullable()->after('product_design');
            $table->string('colour_style', 255)->nullable()->after('metal');
            $table->string('size', 255)->nullable()->after('colour_style');
            $table->string('siblings', 255)->nullable()->after('size');
            $table->string('siblings_collection_name', 255)->nullable()->after('siblings');
            $table->text('uvp_short_paragraph')->nullable()->after('siblings_collection_name');
            $table->text('complementary_products')->nullable()->after('uvp_short_paragraph');
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->dropColumn([
                'material_cost',
                'jewelry_material',
                'product_materials',
                'materials_and_dimensions',
                'product_design',
                'metal',
                'colour_style',
                'size',
                'siblings',
                'siblings_collection_name',
                'uvp_short_paragraph',
                'complementary_products',
            ]);
        });
    }
};
